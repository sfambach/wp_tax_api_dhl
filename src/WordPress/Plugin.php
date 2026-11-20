<?php declare(strict_types=1);

namespace TaxApiDhl\WordPress;

use Taxmod\WordPress\Admin\ListPage;
use TaxApiDhl\Core\Api\ConnectionTest;
use TaxApiDhl\Core\Api\DhlApiException;
use TaxApiDhl\Core\Api\Environment;
use TaxApiDhl\Core\Api\TrackingClient;
use TaxApiDhl\Core\Internetmarke\InternetmarkeClient;
use TaxApiDhl\Core\Mail\DhlNoticeMailParser;
use Taxmod\Core\Import\Mail\OrderMailParser;
use Taxmod\Core\Import\Mail\ShopMailParser;

/**
 * The boundary: the «DHL» tab of the modeller's Configuration page (D-945, D-981), the connection test, the probe
 * and the filter other modules ask. It wires WordPress to the core and decides nothing.
 */
final class Plugin
{
    public const VERSION    = '0.0.22';
    public const CAPABILITY = 'manage_options';
    public const TAB        = 'dhl';
    public const PROBE      = 'taxapidhl_probe';
    public const PORTOKASSE = 'taxapidhl_portokasse';

    public const LAST_CHECK = 'taxmod_dhl_last_check';
    public const LAST_PROBE = 'taxmod_dhl_last_probe';
    public const LAST_PORTOKASSE = 'taxmod_dhl_last_portokasse';

    /** Messages for the next page view of one user, after a redirect. */
    private const NOTICES = 'taxapidhl_notices_';

    /**
     * Whether the modeller is 0.2.172 or later (D-1409, D-1415; ListPage::button/money/date, D-1500) — asked by version, not by loading its classes: method_exists() on
     * ListPage compiled the 67 KB list page on every page view (D-1187; review 2026-11-17, Befund 6).
     */
    private static function modellerFits(): bool
    {
        return class_exists(\Taxmod\WordPress\Plugin::class, false) && version_compare(\Taxmod\WordPress\Plugin::VERSION, '0.2.172', '>=');
    }

    public static function boot(): void
    {
        $plugin = new self();

        // D-1500: the German texts in languages/ — WordPress finds a plugin's own folder only when asked.
        add_action('init', static fn () => load_plugin_textdomain('taxapidhl', false, dirname(plugin_basename(dirname(__DIR__, 2) . '/wp-tax-api-dhl.php')) . '/languages'), 1);

        // D-1409, D-1415: this release stands on the modeller 0.2.172 (shared parser frame, HTTP block, ListPage::acting; button/money/date D-1500); an older one says so.
        add_action('admin_notices', static function (): void {
            if (class_exists(\Taxmod\WordPress\Plugin::class, false) && ! self::modellerFits()) {
                print '<div class="notice notice-warning"><p><strong>Taxmod DHL:</strong> ' . esc_html__('needs the Taxonomy Modeller 0.2.172 or later.', 'taxapidhl') . '</p></div>';
            }
        });
        add_filter('taxmod_config_tabs', $plugin->registerTab(...));
        // ListPage::acting belongs to the modeller, which loads after this file (alphabetical order): hooked once every plugin is.
        add_action('plugins_loaded', static function () use ($plugin): void {
            if (! self::modellerFits() || ! \Taxmod\WordPress\Plugin::handlesAdminActions()) {
                return;
            }

            add_action('admin_post_' . self::PROBE, ListPage::acting($plugin->handleProbe(...), self::settingsUrl()));
            add_action('admin_post_' . self::PORTOKASSE, ListPage::acting($plugin->handlePortokasse(...), self::settingsUrl()));
        });
        // Only on this module's tab: `SettingsScreen::probeForms(...)` compiled the settings screen on every page view (D-1187).
        add_action('admin_footer', static function (): void {
            if (($_GET['page'] ?? '') === 'taxmod-settings' && ($_GET['tab'] ?? '') === self::TAB) {
                SettingsScreen::probeForms();
            }
        });
        add_filter(Tracker::FILTER, static fn (mixed $previous, mixed $number = '', mixed $mode = Tracker::STORED): ?array => (new Tracker())->filter($previous, $number, $mode), 10, 3);
        add_action('admin_notices', $plugin->noticeWithoutTabs(...));
        // Late: this plugin loads before the modeller, which brings the mail contract (0.2.120 or later).
        add_action('plugins_loaded', static function (): void {
            if (self::mailReader() !== null) {
                add_filter('taxmail_parsers', static fn (array $parsers): array => [...$parsers, self::mailReader()]);
            }
        });
    }

    /** DHL's parcel notices, read for the modeller's inbox (D-1020); null without the modeller's mail contract. */
    public static function mailReader(): ?OrderMailParser
    {
        static $reader = null;

        if ($reader === null && class_exists(ShopMailParser::class)) {
            $reader = new DhlNoticeMailParser(self::VERSION);
        }

        return $reader;
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
            'forget'      => static function (): void {
                (new StoredAccess())->forget();
                (new StoredPortokasse())->forget();
            },
            'render'      => fn (): string => (new SettingsScreen(new StoredAccess()))->fields($this->takeNotices()),
            'save'        => static fn (array $post): bool => self::saveAccess($post),
            'guide'       => static fn (): string => SettingsScreen::guide(),
        ] + (self::mailReader() !== null ? ['reader' => self::mailReader()] : []);

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
        if (is_numeric($post['taxapidhl_fresh_hours'] ?? null)) {
            update_option(Tracker::FRESH_HOURS, min(168, max(0, absint($post['taxapidhl_fresh_hours']))), false);
        }

        $access = new StoredAccess();

        if ($access->fromConstants() || ! StoredAccess::vaultAvailable()) {
            return true;
        }

        $field = static fn (string $name): string => is_string($post[$name] ?? null) ? trim(sanitize_text_field($post[$name])) : '';
        $access->save(Environment::tryFrom($field('taxapidhl_environment')) ?? Environment::Sandbox, $field('taxapidhl_api_key'));
        // The Portokasse password may hold any character, so it is taken as typed, only trimmed of line breaks.
        $password = is_string($post['taxapidhl_im_password'] ?? null) ? trim($post['taxapidhl_im_password'], "\r\n") : '';
        (new StoredPortokasse())->save($field('taxapidhl_im_client_id'), $field('taxapidhl_im_client_secret'), sanitize_email($field('taxapidhl_im_username')) ?: $field('taxapidhl_im_username'), $password);

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
            $answer = (new ConnectionTest(new TrackingClient(ApiTransport::make(), $key, $access->environment())))->run();
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
            self::refuse(__('You may not use the DHL connection.', 'taxapidhl'));
        }

        ListPage::nonce(self::PROBE);
        $number = isset($_POST['tracking']) ? (string) preg_replace('/[^A-Za-z0-9]/', '', sanitize_text_field(wp_unslash($_POST['tracking']))) : '';

        $service = isset($_POST['service']) ? sanitize_key(wp_unslash($_POST['service'])) : '';
        $service = in_array($service, TrackingClient::SERVICES, true) ? $service : '';
        $postalCode = isset($_POST['postal_code']) ? (string) preg_replace('/[^A-Za-z0-9]/', '', sanitize_text_field(wp_unslash($_POST['postal_code']))) : '';

        if ($number === '') {
            $this->notice(false, __('Enter a tracking number.', 'taxapidhl'));
            $this->back();
        }

        try {
            $shipment = (new Tracker())->ask($number, $service, $postalCode);

            Tracker::remember($shipment === null ? Tracker::notScannedYet($number) : Tracker::summary($shipment));

            if ($shipment === null) {
                delete_option(self::LAST_PROBE);
                // Like eBay: a number DHL has not scanned yet is «entered», not an error; letters appear after the first scan.
                $this->notice(true, sprintf(
                    /* translators: 1: tracking number, 2: service hint or «automatic» */
                    __('%1$s: tracking number entered — DHL has no scan yet (service: %2$s). Stage: announced.', 'taxapidhl'),
                    $number,
                    $service === '' ? __('automatic', 'taxapidhl') : $service
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

    /** Probe of the Internetmarke step 1: token, balance and print formats. Buys nothing. */
    public function handlePortokasse(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            self::refuse(__('You may not use the DHL connection.', 'taxapidhl'));
        }

        ListPage::nonce(self::PORTOKASSE);
        $access = (new StoredPortokasse())->access();

        if ($access === null) {
            $this->notice(false, __('The Internetmarke access is incomplete — client secret, Portokasse user and password are needed.', 'taxapidhl'));
            $this->back();
        }

        try {
            $client = new InternetmarkeClient(ApiTransport::make());
            $session = $client->session($access);
            update_option(self::LAST_PORTOKASSE, [
                'at'      => current_time('mysql'),
                'user'    => $session->user,
                'balance' => $session->balanceCents,
                'notice'  => $session->notice,
                'formats' => array_map(static fn ($format): array => [
                    'id' => $format->id, 'name' => $format->name, 'type' => $format->pageType, 'width' => $format->widthMm, 'height' => $format->heightMm, 'address' => $format->addressPossible,
                ], $client->pageFormats($session)),
            ], false);
            $this->notice(true, __('Portokasse answered.', 'taxapidhl'));
        } catch (DhlApiException $failure) {
            $this->notice(false, __('Portokasse check failed:', 'taxapidhl') . ' ' . $failure->getMessage());
        }

        $this->back();
    }

    /**
     * The handler as an act (D-1254): an exception becomes a message in the tab's message field with the way back, never an
     * empty page. ListPage is named only here, inside the handler, so the boot does not compile it (D-1244).
     */

    private static function refuse(string $message): never
    {
        ListPage::fail($message, self::settingsUrl());
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
