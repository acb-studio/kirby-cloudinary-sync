panel.plugin("acb/cloudinary-sync", {
  fields: {
    cloudinarySyncAction: {
      props: {
        label: String,
        action: String,
        id: String,
        help: {
          type: String,
          default: "",
        },
        progress: {
          type: String,
          default: "Please wait...",
        },
        success: {
          type: String,
          default: "Success",
        },
        error: {
          type: String,
          default: "An error occurred",
        },
      },
      data() {
        return {
          isBusy: false,
          response: null,
        };
      },
      template: `
          <div class="k-field k-info-field">
            <k-headline>{{ label }}</k-headline>
            <k-box v-if="!response && !isBusy" theme="regular">
              <k-form @submit="execute()">
                <k-button type="submit" :icon="action === 'pull' ? 'download' : 'upload'" theme="negative">
                  Click here to {{ action }}
                </k-button>
              </k-form>
            </k-box>
  
            <k-box v-if="isBusy" theme="regular">
              <k-text>{{ progress }}</k-text>
            </k-box>
            <k-box v-if="response && response.success" theme="positive">
              <k-text v-if="!response.message">{{ success }}</k-text>
              <k-text v-if="response.message" theme="help">{{ response.message }}</k-text>
            </k-box>
            <k-box v-if="response && !response.success" theme="negative">
              <k-text v-if="!response.message">{{ error }}</k-text>
              <k-text v-if="response.message" theme="help">{{ response.message }}</k-text>
            </k-box>

            <footer v-if="help" class="k-field-footer">
                <k-text theme="help" class="k-help">
                    {{ help.replace(/<\\/?p>/g, '') }}
                </k-text>
            </footer>
          </div>
        `,
      methods: {
        async execute() {
          const { id, action } = this.$props;
          this.isBusy = true;

          try {
            if (action !== "bulk-push" && !id) {
              throw new Error("Missing file ID - cannot " + action);
            }

            const response = await this.$api.post(
              `/acb-cloudinary-sync/${action}/${id}`
            );
            this.response = response;

            response?.success &&
              action !== "bulk-push" &&
              setTimeout(() => document.location.reload(), 2000);
          } catch (error) {
            this.response = {
              success: false,
              message: error.message,
            };
          } finally {
            this.isBusy = false;
          }
        },
      },
    },
  },
  components: {
    "k-acb-cloudinary-admin-view": {
      get template() {
        window.executeCloudinaryBulkAction = async (action) => {
          const fieldsEl = document.querySelector(
            "[data-cloudinary-admin-fields]"
          );
          const infoEl = document.querySelector("[data-cloudinary-admin-info]");
          fieldsEl.style.display = "none";
          infoEl.style.display = "flex";

          try {
            const response = await window.panel.$api.post(
              `/acb-cloudinary-sync/bulk-${action}`
            );
            if (!response?.success) {
              throw new Error(response?.message ?? "internal error");
            }

            infoEl.innerText = `Success: ${response.message}`;
          } catch (error) {
            infoEl.innerText = `Error: ${error.message}`;
          }
        };

        return `<k-panel>
        <k-panel-menu />
        <main class="k-panel-main">
          <div class="k-topbar">
            <k-breadcrumb :crumbs="[{icon: 'cog', label: 'Cloudinary Admin', link: 'cloudinary-admin'}]" />
          </div>
          <k-header>Cloudinary Admin</k-header>
          <div class="k-fieldset">
            <k-box data-cloudinary-admin-info theme="info" style="display:none">
              Please wait... (this can take a good while for many assets)
            </k-box>
            <k-grid data-cloudinary-admin-fields>
              <k-column :width="0.5">
                <k-button @click="executeCloudinaryBulkAction('push')" icon="upload" theme="positive" name="action" value="push">
                  Push all files to Cloudinary
                </k-button>
              </k-column>
              <k-column :width="0.5">
                <k-button @click="executeCloudinaryBulkAction('pull')" icon="download" theme="negative" name="action" value="pull">
                  Pull all files from Cloudinary
                </k-button>
              </k-column>
            </k-grid>
          </div>
        </main>
      </k-panel>`;
      },
    },
  },
});
