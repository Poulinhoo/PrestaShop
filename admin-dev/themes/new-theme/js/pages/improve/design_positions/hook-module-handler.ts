/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

interface HookableInfo {
  id: number;
  name: string;
  title: string;
  registered: boolean;
}

/**
 * Handles dynamic hook selector on the "Hook a module" form.
 * When the module select changes, fetches possible hooks via AJAX and
 * repopulates the hook dropdown.
 */
export default class HookModuleHandler {
  private readonly moduleSelector: HTMLSelectElement | null | undefined;

  private readonly hookSelector: HTMLSelectElement | null | undefined;

  private readonly hookUrl: string | null | undefined;

  private readonly availableLabel: string | undefined;

  private readonly registeredLabel: string | undefined;

  constructor() {
    const form = document.querySelector<HTMLFormElement>('[data-hook-url]');

    if (!form) {
      return;
    }

    this.hookUrl = form.dataset.hookUrl ?? null;
    this.availableLabel = form.dataset.labelAvailable ?? 'Available hooks';
    this.registeredLabel = form.dataset.labelRegistered ?? 'Already registered hooks';
    this.moduleSelector = form.querySelector<HTMLSelectElement>('[data-module-selector="true"]');
    this.hookSelector = form.querySelector<HTMLSelectElement>('[data-hook-selector="true"]');

    if (!this.moduleSelector || !this.hookSelector || !this.hookUrl) {
      return;
    }

    this.moduleSelector.addEventListener('change', () => this.onModuleChange());
  }

  private async onModuleChange(): Promise<void> {
    if (!this.moduleSelector || !this.hookSelector || !this.hookUrl) {
      return;
    }

    const moduleId = Number(this.moduleSelector.value);

    if (!moduleId) {
      this.clearHookSelector();

      return;
    }

    try {
      const response = await fetch(this.hookUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({module_id: String(moduleId)}),
      });

      const json = await response.json();

      if (json.hasError || !Array.isArray(json.hooks)) {
        this.clearHookSelector();

        return;
      }

      this.populateHookSelector(json.hooks as HookableInfo[]);
    } catch {
      this.clearHookSelector();
    }
  }

  private populateHookSelector(hooks: HookableInfo[]): void {
    if (!this.hookSelector) {
      return;
    }

    const currentValue = this.hookSelector.value;
    this.clearHookSelector();

    const available = hooks.filter((hook) => !hook.registered);
    const registered = hooks.filter((hook) => hook.registered);

    const buildOption = (hook: HookableInfo): HTMLOptionElement => {
      const option = document.createElement('option');
      option.value = String(hook.id);
      option.text = hook.title ? `${hook.name} (${hook.title})` : hook.name;

      if (String(hook.id) === currentValue) {
        option.selected = true;
      }

      return option;
    };

    if (available.length > 0) {
      const availableGroup = document.createElement('optgroup');

      if (this.availableLabel != null) {
        availableGroup.label = this.availableLabel;
      }
      available.forEach((hook) => availableGroup.appendChild(buildOption(hook)));
      this.hookSelector.appendChild(availableGroup);
    }

    if (registered.length > 0) {
      const registeredGroup = document.createElement('optgroup');

      if (this.registeredLabel != null) {
        registeredGroup.label = this.registeredLabel;
      }
      registeredGroup.disabled = true;
      registered.forEach((hook) => registeredGroup.appendChild(buildOption(hook)));
      this.hookSelector.appendChild(registeredGroup);
    }

    // Re-enable the selector now that choices are available.
    this.setHookSelectorEnabled(true);
  }

  private clearHookSelector(): void {
    if (!this.hookSelector) {
      return;
    }

    // Keep only the placeholder option (the first direct <option> child if any)
    const placeholder = this.hookSelector.querySelector<HTMLOptionElement>(':scope > option');
    this.hookSelector.innerHTML = '';
    if (placeholder) {
      this.hookSelector.appendChild(placeholder);
    }

    // Without choices the selector is unusable — disable it.
    this.setHookSelectorEnabled(false);
  }

  /**
   * Toggles the disabled state of the hook selector.
   * The Symfony form theme renders the field disabled by adding a `disabled`
   * CSS class on both the wrapping `.input-container` and the label. Toggling
   * the `disabled` property on the <select> alone leaves those classes behind,
   * which keeps the field visually greyed out — so they must be synced too.
   */
  private setHookSelectorEnabled(enabled: boolean): void {
    if (!this.hookSelector) {
      return;
    }

    this.hookSelector.disabled = !enabled;

    const container = this.hookSelector.closest<HTMLElement>('.input-container');
    container?.classList.toggle('disabled', !enabled);

    const formGroup = this.hookSelector.closest<HTMLElement>('.form-group');
    formGroup
      ?.querySelector<HTMLElement>('.form-control-label')
      ?.classList.toggle('disabled', !enabled);
  }
}
