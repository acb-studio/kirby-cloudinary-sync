<?php

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Data\Data;
use Kirby\Http\Remote;
use Kirby\Filesystem\F;
use Kirby\Toolkit\A;

use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Api\ApiResponse;

$originalFileUrlComponent = kirby()->component('file::url');
$originalFileVersionComponent = kirby()->component('file::version');

class ACBCloudinarySync
{
    private static bool $isInitialized = false;

    protected static function getCloudinaryConfig()
    {
        $kirby = kirby();
        $cloudinaryKey = $kirby->option('acb.cloudinary.key', '');
        $cloudinarySecret = $kirby->option('acb.cloudinary.secret', '');
        $cloudinaryCloud = $kirby->option('acb.cloudinary.cloud', '');

        is_callable($cloudinaryKey) && ($cloudinaryKey = $cloudinaryKey());
        is_callable($cloudinarySecret) && ($cloudinarySecret = $cloudinarySecret());
        is_callable($cloudinaryCloud) && ($cloudinaryCloud = $cloudinaryCloud());

        return [$cloudinaryKey, $cloudinarySecret, $cloudinaryCloud];
    }

    static function hasCloudinaryConfig(): bool
    {
        return count(array_filter(self::getCloudinaryConfig())) === 3;
    }

    static function initialize(): bool
    {
        if (self::$isInitialized) {
            return true;
        }

        if (!self::hasCloudinaryConfig()) {
            return false;
        }

        [$cloudinaryKey, $cloudinarySecret, $cloudinaryCloud] = self::getCloudinaryConfig();
        Configuration::instance("cloudinary://$cloudinaryKey:$cloudinarySecret@$cloudinaryCloud?secure=true");

        self::$isInitialized = true;
        return true;
    }

    static function push(File $file)
    {
        if (!self::initialize() || !$file->canPushToCloudinary()) {
            return false;
        }

        $kirby = kirby();
        $api = new UploadApi();
        $isDocument = $file->type() === 'document';
        $isVideo = $file->type() === 'video';
        $publicId = $kirby->option(
            'acb.cloudinary.publicId',
            fn($file) => implode('.', array_slice(explode('.', $file->id()), 0, -1))
        )($file);
        $eager = $kirby->option('acb.cloudinary.eagerTransformations', fn() => [])($file);

        $response = $api->upload(
            $file->root(),
            [
                'resource_type' => $isDocument ? 'auto' : $file->type(),
                'public_id' => $publicId,
                ...(($isDocument || $isVideo) ? [] : [
                    'eager' => $eager,
                    'eager_async' => true
                ])
            ]
        );

        self::updateMetaFromResponse($file, $response);

        $removeLocally = $kirby->option('acb.cloudinary.removeAssetsLocally', false);
        $removeLocally && self::overwriteFileWithEmptyPlaceholder($file);

        return true;
    }

    static function delete(File $file)
    {
        $publicId = $file->cloudinary_public_id()->value();
        $resourceType = $file->cloudinary_resource_type()->value();

        if (!self::initialize() || !$publicId || !$resourceType) {
            return;
        }

        $api = new UploadApi();
        return $api->destroy($publicId, ['resource_type' => $resourceType]);
    }

    static function rename(File $newFile, File $oldFile)
    {
        $name = $newFile->name();
        $oldPublicId = $oldFile->cloudinary_public_id()->value();

        if (!self::initialize() || !$name || !$oldPublicId) {
            return;
        }

        $newPublicId = implode('/', array_merge(array_slice(explode('/', $oldPublicId), 0, -1), [$name]));

        $api = new UploadApi();
        $response = $api->rename($oldPublicId, $newPublicId);
        self::updateMetaFromResponse($newFile, $response);
    }

    static function pull(File $file): string
    {
        $id = $file->id();
        $url = $file->cloudinary_url()->value();
        if (!$url) {
            throw new Error("Can not pull $id: Cloudinary URL missing");
        }

        $response = Remote::request($url);
        F::write($file->root(), $response->content());

        self::delete($file);
        self::updateMetaFromResponse($file);

        return $url;
    }

    static function updateMetaFromResponse(File $file, ApiResponse $response = null)
    {
        $file->update([
            'cloudinary_public_id' => $response['public_id'] ?? null,
            'cloudinary_asset_id' => $response['asset_id'] ?? null,
            'cloudinary_size' => $response['bytes'] ?? null,
            'cloudinary_width' => $response['width'] ?? null,
            'cloudinary_height' => $response['height'] ?? null,
            'cloudinary_format' => $response['format'] ?? null,
            'cloudinary_resource_type' => $response['resource_type'] ?? null,
            'cloudinary_url' => $response['secure_url'] ?? null
        ]);
    }

    static function overwriteFileWithEmptyPlaceholder(File $file)
    {
        $emptyGif = base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');
        F::write($file->root(), $emptyGif);
    }

    static function isPushed(File $file): bool
    {
        return $file->cloudinary_public_id()->isNotEmpty();
    }
}

App::plugin('acb/cloudinary-sync', [
    'hooks' => [
        'file.create:after' => function (File $file) {
            ACBCloudinarySync::push($file);
        },
        'file.changeName:after' => function (File $newFile, File $oldFile) {
            ACBCloudinarySync::rename($newFile, $oldFile);
        },
        'file.delete:after' => function (File $file) {
            ACBCloudinarySync::delete($file);
        },
        'file.replace:after' => function (File $newFile, File $oldFile) {
            ACBCloudinarySync::delete($oldFile);
            ACBCloudinarySync::push($newFile);
        }
    ],
    'components' => [
        'file::url' => function (App $kirby, $file, array $options = []) use ($originalFileUrlComponent) {
            if (!ACBCloudinarySync::isPushed($file)) {
                return $originalFileUrlComponent($kirby, $file, $options);
            }

            return $file->cloudinary_url()->value();
        },
        'file::version' => function (App $kirby, File $file, array $options = []) use ($originalFileVersionComponent) {
            if (!ACBCloudinarySync::isPushed($file)) {
                return $originalFileVersionComponent($kirby, $file, $options);
            }

            return $file;
        }
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'acb-cloudinary-sync/push/(:all)',
                'action' => function (string $id) {
                    if (!ACBCloudinarySync::hasCloudinaryConfig()) {
                        return ['success' => false, 'message' => 'Cannot push - no cloudinary config found'];
                    }

                    $site = site();
                    $file = $site->index()->files()->find($id) ?? $site->files()->find($id);
                    if (!($file instanceof File)) {
                        return ['success' => false, 'message' => "File $id does not exist"];
                    }

                    $type = $file->type();
                    if (!$file->canPushToCloudinary()) {
                        throw new Error("Can not push this file (type: $type)");
                    }

                    ACBCloudinarySync::push($file);
                    return ['success' => true, 'message' => "Successfully pushed $id"];
                },
                'method' => 'POST'
            ],
            [
                'pattern' => 'acb-cloudinary-sync/pull/(:all)',
                'action' => function (string $id) {
                    if (!ACBCloudinarySync::hasCloudinaryConfig()) {
                        return ['success' => false, 'message' => 'Cannot pull - no cloudinary config found'];
                    }

                    $site = site();
                    $file = $site->index()->files()->find($id) ?? $site->files()->find($id);
                    if (!($file instanceof File)) {
                        return ['success' => false, 'message' => "File $id does not exist"];
                    }

                    $url = ACBCloudinarySync::pull($file);
                    return ['success' => true, 'message' => "Successfully pulled asset from $url - asset removed from Cloudinary"];
                },
                'method' => 'POST'
            ],
            [
                'pattern' => 'acb-cloudinary-sync/bulk-push',
                'action' => function () {
                    $kirby = kirby();
                    if (!$kirby->user()?->role()->permissions()->for('access', 'system')) {
                        return ['success' => false, 'message' => 'Insufficient permission to perform bulk action'];
                    }

                    if (!ACBCloudinarySync::hasCloudinaryConfig()) {
                        return ['success' => false, 'message' => 'Cannot push - no cloudinary config found'];
                    }

                    $site = $kirby->site();
                    $users = $kirby->users();
                    $files = $site->index()->files()->add($site->files())->add($users->files())
                        ->filter(fn(File $file) => $file->canPushToCloudinary() && !ACBCloudinarySync::isPushed($file));

                    $errorMessage = null;
                    $successCount = count(array_filter(array_values($files->map(function ($file) use (&$errorMessage) {
                        try {
                            return ACBCloudinarySync::push($file);
                        } catch (Throwable $error) {
                            $errorMessage = $file->id() . ': ' . $error->getMessage();
                            return false;
                        }
                    })->data)));
                    $count = $files->count();

                    return [
                        'success' => !!$successCount || !$count,
                        'message' => "$successCount of $count files successfully pushed" . ($errorMessage ? " (error message: $errorMessage)" : '')
                    ];
                },
                'method' => 'POST'
            ],
            [
                'pattern' => 'acb-cloudinary-sync/bulk-pull',
                'action' => function () {
                    $kirby = kirby();
                    if (!$kirby->user()?->role()->permissions()->for('access', 'system')) {
                        return ['success' => false, 'message' => 'Insufficient permission to perform bulk action'];
                    }

                    if (!ACBCloudinarySync::hasCloudinaryConfig()) {
                        return ['success' => false, 'message' => 'Cannot pull - no cloudinary config found'];
                    }

                    $site = $kirby->site();
                    $users = $kirby->users();
                    $files = $site->index()->files()->add($site->files())->add($users->files())
                        ->filter(fn(File $file) => ACBCloudinarySync::isPushed($file));
                    $files->map(fn($file) => ACBCloudinarySync::pull($file));
                    $count = $files->count();

                    return [
                        'success' => true,
                        'message' => "$count files pulled, assets removed from Cloudinary"
                    ];
                },
                'method' => 'POST'
            ]
        ],
    ],
    'fileMethods' => [
        'cloudinaryAssetSize' => fn() => ACBCloudinarySync::isPushed($this) ? F::niceSize($this->cloudinary_size()->int()) : $this->niceSize(),
        'canPushToCloudinary' => function () {
            $allowedTypes = kirby()->option('acb.cloudinary.assetTypes', ['image']);
            is_callable($allowedTypes) && ($allowedTypes = $allowedTypes());

            $canPushImage = A::has($allowedTypes, 'image') && $this->type() === 'image';
            $canPushVideo = A::has($allowedTypes, 'video') && $this->type() === 'video';
            $canPushPdf = A::has($allowedTypes, 'pdf') && $this->type() === 'document' && $this->extension() === 'pdf';

            return $canPushImage || $canPushVideo || $canPushPdf;
        }
    ],
    'fields' => [
        'cloudinarySyncAction' => [
            'computed' => [
                'id' => fn() => $this->model()->id() ?: null
            ]
        ]
    ],
    'areas' => [
        'cloudinaryAdmin' => fn($kirby) => $kirby->option('acb.cloudinary.adminArea', true) ? [
            'label' => 'Cloudinary Admin',
            'icon' => 'cog',
            'menu' => fn($areas = [], $permissions = []) => $permissions['access']['system'] ?? false,
            'link' => 'cloudinary-admin',
            'views' => [
                [
                    'pattern' => 'cloudinary-admin',
                    'action'  => function () use ($kirby) {
                        if (!$kirby->user()?->role()->permissions()->for('access', 'system')) {
                            throw new Error('Insufficient permission to see this area');
                        }

                        return [
                            'component' => 'k-acb-cloudinary-admin-view',
                            'title' => 'Cloudinary admin',
                        ];
                    }
                ]
            ]
        ] : []
    ],
    'blueprints' => [
        'files/cloudinary' => fn() => Data::read(__DIR__ . '/cloudinary-file.yml')
    ]
]);
