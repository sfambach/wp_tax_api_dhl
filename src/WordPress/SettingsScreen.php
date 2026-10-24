<?php declare(strict_types=1);

namespace TaxApiDhl\WordPress;

use TaxApiDhl\Core\Api\Environment;

/**
 * The «DHL» tab parts: server and key (Access), the probe (Settings) and the «How to connect» box. Returns their HTML
 * (CD-8); the modeller wraps them in its form and draws Save, Test connection and Delete (D-981). The key is never shown.
 */
final class SettingsScreen
{
    public function __construct(private readonly StoredAccess $access)
    {
    }

    public function credentials(): string
    {
        if ($this->access->fromConstants()) {
            return '<p>' . esc_html(sprintf(
                /* translators: %s: sandbox or production */
                __('Set in wp-config.php (TAXDHL_API_KEY), server: %s. This takes precedence over the fields.', 'taxapidhl'),
                $this->access->environment()->value
            )) . '</p>' . (StoredAccess::vaultAvailable() ? $this->portokasseFields() : '');
        }

        if (! StoredAccess::vaultAvailable()) {
            return '<p>' . esc_html__('This Taxonomy Modeller has no vault yet (0.1.31 or later), so the key can only be defined in wp-config.php:', 'taxapidhl') . '</p>'
                . '<pre>' . esc_html("define('TAXDHL_API_KEY', '…');\ndefine('TAXDHL_ENVIRONMENT', 'sandbox'); // or 'production'") . '</pre>';
        }

        $html = '<p>' . esc_html__('The API key of your app in the DHL developer portal (developer.dhl.com → My Apps). It is stored encrypted.', 'taxapidhl') . '</p>';

        if ($this->access->keyBroken()) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html__('The stored API key no longer opens — the salts in wp-config.php changed. Enter it again.', 'taxapidhl') . '</p></div>';
        }

        $current = $this->access->environment();
        $options = '';

        foreach ([Environment::Sandbox->value => __('Sandbox (sample data, for the connection test)', 'taxapidhl'), Environment::Production->value => __('Production (real shipments)', 'taxapidhl')] as $value => $label) {
            $options .= '<option value="' . esc_attr($value) . '"' . selected($current->value, $value, false) . '>' . esc_html($label) . '</option>';
        }

        $html .= '<table class="form-table" role="presentation"><tbody>'
            . '<tr><th scope="row"><label for="taxapidhl-environment">' . esc_html__('Server', 'taxapidhl') . '</label></th>'
            . '<td><select id="taxapidhl-environment" name="taxapidhl_environment">' . $options . '</select></td></tr>'
            . '<tr><th scope="row"><label for="taxapidhl-api-key">' . esc_html__('API key', 'taxapidhl') . '</label></th>'
            . '<td><input type="password" class="regular-text" autocomplete="off" id="taxapidhl-api-key" name="taxapidhl_api_key" value="" placeholder="'
            . esc_attr($this->access->hasStoredKey() ? __('stored — leave empty to keep it', 'taxapidhl') : '') . '"></td></tr>'
            . '</tbody></table>';

        $html .= $this->portokasseFields();
        $last = get_option(Plugin::LAST_CHECK);

        if (is_array($last) && isset($last['at'])) {
            $html .= '<p>' . esc_html(sprintf(
                /* translators: 1: date, 2: sandbox or production, 3: result */
                __('Last test %1$s (%2$s): %3$s', 'taxapidhl'),
                (string) $last['at'],
                (string) ($last['environment'] ?? ''),
                (string) ($last['message'] ?? '')
            )) . '</p>';
        }

        return $html;
    }

    /** @param list<array{ok: bool, text: string}> $notices */
    public function fields(array $notices): string
    {
        $html = '';

        foreach ($notices as $notice) {
            $html .= '<div class="notice ' . ($notice['ok'] ? 'notice-success' : 'notice-error') . ' inline"><p>' . esc_html($notice['text']) . '</p></div>';
        }

        $calls = Tracker::callsToday();
        $html .= '<p class="description">' . esc_html(sprintf(
            /* translators: 1: calls today, 2: daily budget */
            __('DHL calls today: %1$d of %2$d.', 'taxapidhl'),
            $calls['calls'],
            Tracker::DAILY_BUDGET
        )) . '</p>';

        return $html . ($this->access->apiKey() === null ? '' : $this->probe()) . $this->portokasse();
    }

    /** Internetmarke (step 1 of the label plan): app secret and Portokasse login, the secrets never shown again. */
    private function portokasseFields(): string
    {
        $shown = (new StoredPortokasse($this->access))->shown();
        $kept = __('stored — leave empty to keep it', 'taxapidhl');

        return '<h3>' . esc_html__('Internetmarke (Portokasse)', 'taxapidhl') . '</h3>'
            . '<p>' . esc_html__('For letter postage. The app needs the API «Post DE Internetmarke»; secret and password are stored encrypted. For tests use a developer Portokasse — its stamps print «Muster».', 'taxapidhl') . '</p>'
            . '<table class="form-table" role="presentation"><tbody>'
            . self::inputRow('im_client_id', __('Client ID (API key)', 'taxapidhl'), 'text', $shown['client_id'], __('empty — the API key above', 'taxapidhl'))
            . self::inputRow('im_client_secret', __('Client secret', 'taxapidhl'), 'password', '', $shown['has_secret'] ? $kept : '')
            . self::inputRow('im_username', __('Portokasse user (e-mail)', 'taxapidhl'), 'text', $shown['username'], '')
            . self::inputRow('im_password', __('Portokasse password', 'taxapidhl'), 'password', '', $shown['has_password'] ? $kept : '')
            . '</tbody></table>';
    }

    private static function inputRow(string $name, string $label, string $type, string $value, string $placeholder): string
    {
        return '<tr><th scope="row"><label for="taxapidhl-' . esc_attr($name) . '">' . esc_html($label) . '</label></th>'
            . '<td><input type="' . esc_attr($type) . '" class="regular-text" autocomplete="off" id="taxapidhl-' . esc_attr($name)
            . '" name="taxapidhl_' . esc_attr($name) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '"></td></tr>';
    }

    /** The Portokasse probe: balance and print formats, label printers first. Buys nothing. */
    private function portokasse(): string
    {
        $html = '<h2>' . esc_html__('Portokasse (probe, nothing is bought)', 'taxapidhl') . '</h2>';

        if ((new StoredPortokasse($this->access))->access() !== null) {
            $html .= '<p><button type="submit" form="taxapidhl-portokasse" class="button">' . esc_html__('Check Portokasse', 'taxapidhl') . '</button></p>';
        }

        $last = get_option(Plugin::LAST_PORTOKASSE);

        if (! is_array($last) || ! isset($last['at'])) {
            return $html;
        }

        $html .= '<p>' . esc_html(sprintf(
            /* translators: 1: user, 2: balance in euros, 3: date */
            __('%1$s: balance %2$s € (read %3$s)', 'taxapidhl'),
            (string) ($last['user'] ?? ''),
            number_format_i18n(((int) ($last['balance'] ?? 0)) / 100, 2),
            (string) $last['at']
        )) . ((string) ($last['notice'] ?? '') === '' ? '' : ' ' . esc_html((string) $last['notice'])) . '</p>'
            . '<table class="widefat striped" style="max-width:60em"><thead><tr><th>' . esc_html__('ID', 'taxapidhl') . '</th><th>' . esc_html__('Print format', 'taxapidhl')
            . '</th><th>' . esc_html__('Type', 'taxapidhl') . '</th><th>' . esc_html__('Size (mm)', 'taxapidhl') . '</th><th>' . esc_html__('Address', 'taxapidhl') . '</th></tr></thead><tbody>';

        foreach (is_array($last['formats'] ?? null) ? $last['formats'] : [] as $format) {
            $format = is_array($format) ? $format : [];
            $html .= '<tr><td>' . esc_html((string) ($format['id'] ?? '')) . '</td><td>' . esc_html((string) ($format['name'] ?? '')) . '</td><td>'
                . esc_html((string) ($format['type'] ?? '')) . '</td><td>' . esc_html(((float) ($format['width'] ?? 0)) . ' × ' . ((float) ($format['height'] ?? 0)))
                . '</td><td>' . (empty($format['address']) ? '–' : '✓') . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** A form of its own, tied by `form=` to the probe form in the footer, because the tab itself sits inside the modeller's form (D-981). */
    private function probe(): string
    {
        $html = '<h2>' . esc_html__('Track a shipment (probe, nothing is written)', 'taxapidhl') . '</h2>'
            . '<p><input type="text" form="taxapidhl-probe" name="tracking" class="regular-text" placeholder="' . esc_attr__('Tracking number', 'taxapidhl') . '"> '
            . '<button type="submit" form="taxapidhl-probe" class="button">' . esc_html__('Track', 'taxapidhl') . '</button></p>';

        $last = get_option(Plugin::LAST_PROBE);

        if (! is_array($last) || ! is_array($last['summary'] ?? null)) {
            return $html;
        }

        $summary = $last['summary'];
        $html .= '<p>' . esc_html(sprintf(
            /* translators: 1: tracking number, 2: service, 3: stage, 4: status, 5: date read */
            __('%1$s (%2$s): stage %3$s — %4$s (read %5$s)', 'taxapidhl'),
            (string) ($summary['tracking_number'] ?? ''),
            (string) ($last['service'] ?? ''),
            (string) ($summary['stage'] ?? ''),
            (string) ($summary['status'] ?? ''),
            (string) ($last['at'] ?? '')
        )) . '</p><table class="widefat striped" style="max-width:60em"><thead><tr><th>' . esc_html__('Time', 'taxapidhl') . '</th><th>'
            . esc_html__('Code', 'taxapidhl') . '</th><th>' . esc_html__('Event', 'taxapidhl') . '</th><th>' . esc_html__('Place', 'taxapidhl') . '</th></tr></thead><tbody>';

        foreach (is_array($last['events'] ?? null) ? $last['events'] : [] as $event) {
            $event = is_array($event) ? $event : [];
            $html .= '<tr><td>' . esc_html((string) ($event['at'] ?? '')) . '</td><td>' . esc_html((string) ($event['code'] ?? '')) . '</td><td>'
                . esc_html((string) ($event['text'] ?? '')) . '</td><td>' . esc_html((string) ($event['place'] ?? '')) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** The probes' own forms, printed after the modeller's page so they do not nest in the tab's form. */
    public static function probeForms(): void
    {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

        if ($page !== 'taxmod-settings' || $tab !== Plugin::TAB || ! current_user_can(Plugin::CAPABILITY)) {
            return;
        }

        foreach (['taxapidhl-probe' => Plugin::PROBE, 'taxapidhl-portokasse' => Plugin::PORTOKASSE] as $id => $action) {
            print '<form id="' . esc_attr($id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
                . wp_nonce_field($action, '_wpnonce', false, false) . '</form>';
        }
    }

    /** The steps of the «How to connect» box the modeller draws beside the tab (D-963), escaped. */
    public static function guide(): string
    {
        $steps = [
            __('Sign in at developer.dhl.com and create an app with the API «Shipment Tracking – Unified». Start with the sandbox: it is granted at once.', 'taxapidhl'),
            __('Copy the API key of the app (My Apps → app → Show key). Never post it in a chat. The API secret is not needed for tracking.', 'taxapidhl'),
            __('Choose «Sandbox», enter the key, save and click «Test connection». If the test says the web server cannot reach DHL, your host blocks outgoing calls — applying for production is then pointless.', 'taxapidhl'),
            __('When the test works, request production access for the app in the portal. Once granted, switch the server to «Production» and test again.', 'taxapidhl'),
            __('Optional, for letter postage: add «Post DE Internetmarke» to the app, enter its client secret and your Portokasse login below the key, save and click «Check Portokasse». For tests ask it-csp@deutschepost.de to turn a second Portokasse into a developer Portokasse.', 'taxapidhl'),
            __('Use «Track» to look at one real shipment. Other modules (eBay shipping) ask this module per tracking number; at most 200 calls a day, each number at most every two hours.', 'taxapidhl'),
        ];

        return '<ol>' . implode('', array_map(static fn (string $step): string => '<li>' . esc_html($step) . '</li>', $steps)) . '</ol>';
    }
}
