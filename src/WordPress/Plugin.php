<?php declare(strict_types=1);

namespace TaxApiDhl\WordPress;

use TaxApiDhl\Core\Api\ConnectionTest;
use TaxApiDhl\Core\Api\DhlApiException;
use TaxApiDhl\Core\Api\Environment;
use TaxApiDhl\Core\Api\TrackingClient;

/**
 * The boundary: the «DHL» tab of the modeller's Configuration page (D-945, D-981), the connection test, the probe
 * and the filter other modules ask. It wires WordPress to the core and decides nothing.
 */
final class Plugin
{
    public const VERSION    = '0.0.1';
    public const CAPABILITY = 'manage_options';
    public const TAB        = 'dhl';
    public const PROBE      = 'taxapidhl_probe';

    public const LAST_CHECK = 'taxmod_dhl_last_check';
    public const LAST_PROBE = 'taxmod_dhl_last_probe';

    /** Messages for the next page view of one user, after a redirect. */
    private const NOTICES = 'taxapidhl_notices_';

    public static function boot(): void
    {
        $plugin = new self();

        add_filter('taxmod_config_tabs', $plugin->registerTab(...));
        add_action('admin_post_' . self::PROBE, $plugin->handleProbe(...));
        add_action('admin_footer', SettingsScreen::probeForm(...));
        add_filter(Tracker::FILTER, static fn (mixed $previous, mixed $number = ''): ?array => (new Tracker())->filter($previous, $number), 10, 2);
        add_action('admin_notices', $plugin->noticeWithoutTabs(...));
    }

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public function registerTab(array $tabs): array
    {
        $tabs[self::TAB] = [
            'title'       => __('DHL', 'taxapidhl'),
            'version'     => self::VERSION,
            'credentials' => static fn (): string => (new SettingsScreen(new StoredAccess()))->credentials(),
            'test'        => static fn (): string => self::testConnection(),
            'forget'      => static fn () => (new StoredAccess())->forget(),
            'render'      => fn (): string => (new SettingsScreen(new StoredAccess()))->fields($this->takeNotices()),
            'save'        => static fn (array $post): bool => self::saveAccess($post),
            'guide'       => static fn (): string => SettingsScreen::guide(),
        ];

        return $tabs;
    }

    /** Without the modeller's tabs there is no place for the settings; say so instead of adding a menu. */
    public function noticeWithoutTabs(): void
    {
        if (defined('Taxmod\WordPress\Admin\SettingsScreen::TABS_FILTER') || ! current_user_can(self::CAPABILITY)) {
            return;
        }

        print '<div class="notice notice-warning"><p><strong>Taxmod DHL:</strong> '
            . esc_html__('needs the Taxonomy Modeller with configuration tabs (0.1.27 or later).', 'taxapidhl') . '</p></div>';
    }

    /**
     * Validates and sanitizes the fields; the modeller has checked capability and nonce (D-945).
     *
     * @param array<string, mixed> $post already unslashed
     */
    private static function saveAccess(array $post): bool
    {
        $access = new StoredAccess();

        if ($access->fromConstants() || ! StoredAccess::vaultAvailable()) {
            return true;
        }

        $field = static fn (string $name): string => is_string($post[$name] ?? null) ? trim(sanitize_text_field($post[$name])) : '';
        $access->save(Environment::tryFrom($field('taxapidhl_environment')) ?? Environment::Sandbox, $field('taxapidhl_api_key'));

        return $access->apiKey() !== null;
    }

    /** «Test connection» of the modeller (D-981), run after saving. Returns one sentence. */
    private static function testConnection(): string
    {
        $access = new StoredAccess();
        $key = $access->apiKey();

        if ($key === null) {
            return __('The API key is missing — enter and save it first, or define it in wp-config.php.', 'taxapidhl');
        }

        try {
            $answer = (new ConnectionTest(new TrackingClient(new WpHttpTransport(), $key, $access->environment())))->run();
            update_option(self::LAST_CHECK, ['ok' => true, 'at' => current_time('mysql'), 'environment' => $access->environment()->value, 'message' => $answer], false);

            return sprintf(
                /* translators: 1: sandbox or production, 2: what DHL answered */
                __('Connection works (%1$s): %2$s.', 'taxapidhl'),
                $access->environment()->value,
                $answer
            );
        } catch (DhlApiException $failure) {
            update_option(self::LAST_CHECK, ['ok' => false, 'at' => current_time('mysql'), 'environment' => $access->environment()->value, 'message' => $failure->getMessage()], false);

            return __('Connection failed:', 'taxapidhl') . ' ' . $failure->getMessage();
        }
    }

    /** Probe: tracks one number and shows the answer in the tab. Nothing is written to the model. */
    public function handleProbe(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You may not use the DHL connection.', 'taxapidhl'), 403);
        }

        check_admin_referer(self::PROBE);
        $number = isset($_POST['tracking']) ? (string) preg_replace('/[^A-Za-z0-9]/', '', sanitize_text_field(wp_unslash($_POST['tracking']))) : '';

        if ($number === '') {
            $this->notice(false, __('Enter a tracking number.', 'taxapidhl'));
            $this->back();
        }

        try {
            $shipment = (new Tracker())->ask($number);

            if ($shipment === null) {
                delete_option(self::LAST_PROBE);
                $this->notice(false, sprintf(
                    /* translators: %s: tracking number */
                    __('DHL knows no shipment %s.', 'taxapidhl'),
                    $number
                ));
            } else {
                update_option(self::LAST_PROBE, [
                    'at'      => current_time('mysql'),
                    'summary' => Tracker::summary($shipment),
                    'service' => $shipment->service,
                    'events'  => array_map(static fn ($event): array => [
                        'at' => $event->timestamp, 'code' => $event->statusCode, 'text' => $event->description !== '' ? $event->description : $event->status, 'place' => $event->locality,
                    ], $shipment->events),
                ], false);
                $this->notice(true, sprintf(
                    /* translators: %s: tracking number */
                    __('Shipment %s read.', 'taxapidhl'),
                    $number
                ));
            }
        } catch (DhlApiException $failure) {
            $this->notice(false, __('Tracking failed:', 'taxapidhl') . ' ' . $failure->getMessage());
        }

        $this->back();
    }

    public static function settingsUrl(): string
    {
        return admin_url('admin.php?page=taxmod-settings&tab=' . self::TAB);
    }

    private function back(): never
    {
        wp_safe_redirect(self::settingsUrl());
        exit;
    }

    private function notice(bool $ok, string $text): void
    {
        $key = self::NOTICES . get_current_user_id();
        $notices = get_transient($key);
        $notices = is_array($notices) ? $notices : [];
        $notices[] = ['ok' => $ok, 'text' => $text];
        set_transient($key, $notices, 10 * MINUTE_IN_SECONDS);
    }

    /** @return list<array{ok: bool, text: string}> */
    private function takeNotices(): array
    {
        $key = self::NOTICES . get_current_user_id();
        $notices = get_transient($key);
        delete_transient($key);

        return is_array($notices)
            ? array_values(array_map(static fn (mixed $n): array => ['ok' => (bool) ($n['ok'] ?? false), 'text' => (string) ($n['text'] ?? '')], $notices))
            : [];
    }
}
