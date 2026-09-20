<?php
if (!defined('ABSPATH')) {
    exit;
}

// Guided multi-step "New Storm Chase" flow — required core fields first (chase type, date,
// miles, states, chase partner), then optional weather details, then an optional chase map
// upload, landing on the exact same post-edit screen used for every other chase once done.
// Intercepts the CPT's normal "Add New" (post-new.php) entirely — see redirect_add_new() —
// so there's no way to reach the raw editor for a brand-new chase without going through this
// first. Editing an already-created chase is completely untouched.
//
// Deliberately does NOT duplicate StormChasesData's sanitization/validation: each step here
// is a real form POST containing storm_chases_nonce plus the exact same field names the real
// meta box (templates/stormchases.php) uses, and calling wp_insert_post()/wp_update_post()
// fires the same save_post_storm_chase hook (StormChaseTemplate::save_post()) that already
// processes them from $_POST — this class only adds its own extra required-field gate in
// front of step 1, and reuses the real field-repeater HTML (sc_render_tornado_entry_html()
// etc.) and admin.js for steps 2/3 rather than rebuilding any of that.
class Storm_Chases_Wizard {
    const PAGE_SLUG = 'storm-chases-wizard';
    const STEP1_NONCE_ACTION = 'storm_chases_wizard_step1';
    const STEP2_NONCE_ACTION = 'storm_chases_wizard_step2';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('load-post-new.php', [$this, 'redirect_add_new']);
        add_action('admin_post_storm_chases_wizard_step1', [$this, 'handle_step1']);
        add_action('admin_post_storm_chases_wizard_step2', [$this, 'handle_step2']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    // A null $menu_title registers the page (route + capability check) without adding a
    // second, redundantly-labeled "Add New" entry next to the CPT's own auto-generated one
    // — that existing entry already lands here via redirect_add_new() below.
    public function add_menu_page(): void {
        add_submenu_page(
            'edit.php?post_type=storm_chase',
            __('Add New Storm Chase', 'stormchases'),
            null,
            'edit_posts',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    // Catches every path to post-new.php for this post type — the admin menu's own "Add
    // New" link, the list table's "Add New" button, a bookmarked URL — not just the menu
    // link above, which is what actually makes the wizard the only way in rather than just
    // an alternate one.
    public function redirect_add_new(): void {
        $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
        if ($post_type === 'storm_chase') {
            wp_safe_redirect(admin_url('edit.php?post_type=storm_chase&page=' . self::PAGE_SLUG));
            exit;
        }
    }

    private function current_step(): int {
        $step = isset($_GET['step']) ? absint($_GET['step']) : 1;
        return in_array($step, [1, 2, 3], true) ? $step : 1;
    }

    // Loads a ?post= draft for steps 2/3, confirming it's really a storm_chase this user
    // may edit — redirects back to step 1 rather than fatal-erroring on a stale/tampered
    // link (e.g. the draft was since trashed).
    private function load_draft(): ?WP_Post {
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        $post = $post_id ? get_post($post_id) : null;
        if (!$post || $post->post_type !== 'storm_chase' || !current_user_can('edit_post', $post_id)) {
            wp_safe_redirect(admin_url('edit.php?post_type=storm_chase&page=' . self::PAGE_SLUG));
            exit;
        }
        return $post;
    }

    public function enqueue_assets($hook): void {
        if ($hook !== 'storm_chase_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');

        $admin_deps = ['jquery', 'jquery-ui-sortable', 'wp-mediaelement', 'wp-data'];
        if ($this->current_step() === 2) {
            sc_enqueue_leaflet();
            $admin_deps[] = 'leaflet';
        }

        wp_enqueue_script(
            'storm-chases-admin',
            STORM_CHASES_URL . 'assets/js/admin.js',
            $admin_deps,
            STORM_CHASES_VERSION,
            true
        );

        $post = null;
        if ($this->current_step() > 1) {
            $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
            $post = $post_id ? get_post($post_id) : null;
        }

        $localize_data = [
            'nonce' => wp_create_nonce('wp_rest'),
            'restUrl' => esc_url_raw(rest_url()),
            'ajaxUrl' => esc_url_raw(admin_url('admin-ajax.php')),
            'maxFileSize' => absint(get_option('storm_chases_max_file_size', 10 * 1024 * 1024)),
            'supportedFileTypes' => array_map('esc_attr', get_option('storm_chases_supported_file_types', ['image/jpeg', 'image/png', 'image/gif', 'application/vnd.google-earth.kml+xml'])),
        ];
        if ($post) {
            $data_handler = new StormChasesData();
            $chase_data = $data_handler->get_chase_data($post->ID);
            $localize_data['postId'] = $post->ID;
            $localize_data['tornadoCount'] = count($chase_data['tornadoes'] ?? []);
        }

        wp_localize_script('storm-chases-admin', 'stormChasesSettings', $localize_data);

        wp_enqueue_style('storm-chases-admin', STORM_CHASES_URL . 'assets/css/admin.css', [], STORM_CHASES_VERSION);
        wp_enqueue_style('jquery-ui', STORM_CHASES_URL . 'assets/vendor/jquery-ui/jquery-ui.css', [], '1.12.1');
    }

    public function render_page(): void {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions to create a storm chase.', 'stormchases'), 403);
        }

        $step = $this->current_step();
        $post = $step > 1 ? $this->load_draft() : null;

        $errors = get_transient('storm_chases_wizard_errors_' . get_current_user_id());
        $sticky = get_transient('storm_chases_wizard_sticky_' . get_current_user_id());
        delete_transient('storm_chases_wizard_errors_' . get_current_user_id());
        delete_transient('storm_chases_wizard_sticky_' . get_current_user_id());

        include STORM_CHASES_DIR . 'templates/admin/wizard.php';
    }

    // Step 1: the required-field gate. Validates server-side (never trusts the browser's
    // own `required` attributes alone), creates the draft post on success — which is what
    // actually saves chase_type/chasestates/chasepartners/chasemiles/chasechasers, via
    // save_post_storm_chase firing from wp_insert_post() below, not any code in this method.
    public function handle_step1(): void {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions to create a storm chase.', 'stormchases'), 403);
        }
        check_admin_referer(self::STEP1_NONCE_ACTION, 'storm_chases_wizard_nonce');

        $sticky = [
            'title' => isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '',
            'chase_date' => isset($_POST['wizard_chase_date']) ? sanitize_text_field(wp_unslash($_POST['wizard_chase_date'])) : '',
            'chase_type' => isset($_POST['chase_type']) ? sanitize_text_field(wp_unslash($_POST['chase_type'])) : '',
            'chasestates' => isset($_POST['chasestates']) ? sanitize_text_field(wp_unslash($_POST['chasestates'])) : '',
            'chasepartners' => isset($_POST['chasepartners']) ? sanitize_text_field(wp_unslash($_POST['chasepartners'])) : '',
            'chasechasers' => isset($_POST['chasechasers']) ? sanitize_text_field(wp_unslash($_POST['chasechasers'])) : '',
            'chasemiles' => isset($_POST['chasemiles']) ? sanitize_text_field(wp_unslash($_POST['chasemiles'])) : '',
        ];

        $errors = self::validate_step1($sticky);
        if (!empty($errors)) {
            set_transient('storm_chases_wizard_errors_' . get_current_user_id(), $errors, 5 * MINUTE_IN_SECONDS);
            set_transient('storm_chases_wizard_sticky_' . get_current_user_id(), $sticky, 5 * MINUTE_IN_SECONDS);
            wp_safe_redirect(admin_url('edit.php?post_type=storm_chase&page=' . self::PAGE_SLUG . '&step=1'));
            exit;
        }

        $post_id = wp_insert_post([
            'post_type' => 'storm_chase',
            'post_status' => 'draft',
            'post_title' => $sticky['title'],
            'post_date' => $sticky['chase_date'] . ' 12:00:00',
        ], true);

        if (is_wp_error($post_id)) {
            set_transient('storm_chases_wizard_errors_' . get_current_user_id(), [$post_id->get_error_message()], 5 * MINUTE_IN_SECONDS);
            set_transient('storm_chases_wizard_sticky_' . get_current_user_id(), $sticky, 5 * MINUTE_IN_SECONDS);
            wp_safe_redirect(admin_url('edit.php?post_type=storm_chase&page=' . self::PAGE_SLUG . '&step=1'));
            exit;
        }

        wp_safe_redirect(admin_url('edit.php?post_type=storm_chase&page=' . self::PAGE_SLUG . '&step=2&post=' . $post_id));
        exit;
    }

    // 0 miles is valid — deliberately not using empty()/!$value, which would reject "0".
    // Public + static: a pure function of $sticky with no side effects, callable directly
    // for testing.
    public static function validate_step1(array $sticky): array {
        $errors = [];

        if ($sticky['chase_type'] === '' || !in_array($sticky['chase_type'], StormChasesData::get_chase_types(), true)) {
            $errors[] = __('Chase Type is required.', 'stormchases');
        }
        if ($sticky['chasestates'] === '') {
            $errors[] = __('States Chased is required — enter at least one state, or describe where if it doesn\'t fit neatly (e.g. "Gulf of Mexico").', 'stormchases');
        }
        if ($sticky['chasepartners'] === '') {
            $errors[] = __('Chase Partners is required — type "Solo" if you chased alone, or list who you were with.', 'stormchases');
        }
        if ($sticky['chasemiles'] === '' || !is_numeric($sticky['chasemiles']) || (float) $sticky['chasemiles'] < 0) {
            $errors[] = __('Miles Logged is required — enter 0 if you didn\'t track mileage.', 'stormchases');
        }

        if ($sticky['chase_date'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sticky['chase_date'])) {
            $errors[] = __('Chase Date is required.', 'stormchases');
        } else {
            $timestamp = strtotime($sticky['chase_date']);
            $year = (int) gmdate('Y', $timestamp);
            if ($timestamp === false || $year < 1950 || $timestamp > time() + DAY_IN_SECONDS) {
                $errors[] = __('Chase Date doesn\'t look valid — it should be a real past (or very recent) date.', 'stormchases');
            }
        }

        return $errors;
    }

    // Step 2: optional weather details. Just re-fires save_post_storm_chase against the
    // already-created draft — wp_update_post() with no real content change is enough to
    // trigger it, same mechanism as step 1's wp_insert_post().
    public function handle_step2(): void {
        check_admin_referer(self::STEP2_NONCE_ACTION, 'storm_chases_wizard_nonce');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post = $post_id ? get_post($post_id) : null;
        if (!$post || $post->post_type !== 'storm_chase' || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Invalid storm chase draft.', 'stormchases'), 403);
        }

        // edit_date: true — see the matching comment on set_post_name_from_chasedate() in
        // storm_chase.php. Without it, this no-op-content update (just here to re-fire
        // save_post_storm_chase) would silently reset the draft's post_date to now.
        wp_update_post(['ID' => $post_id, 'edit_date' => true]);

        wp_safe_redirect(admin_url('edit.php?post_type=storm_chase&page=' . self::PAGE_SLUG . '&step=3&post=' . $post_id));
        exit;
    }
}
