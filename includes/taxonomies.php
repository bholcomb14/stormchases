<?php
if (!defined('ABSPATH')) {
    exit;
}

// Chase People "profiles" — one non-hierarchical taxonomy on storm_chase, each real person a
// term. show_ui => true gives WordPress's own native tag-box meta box (autocomplete-as-you-
// type, "add new" on Enter) for free — exactly the "add a user, or type a name to create a
// new one" behavior asked for, with no hand-built autocomplete widget needed.
//
// One taxonomy, not two — a person is the same profile (website, location, "other chases"
// popup) whether they were a Chase Partner on one chase or a Chaser Encountered on another.
// The role for any *given* chase was never really a taxonomy concern in the first place: it's
// fully captured by which of the two legacy free-text fields (chasepartners/chasechasers on
// chase_data) their name is in for that chase — those fields are untouched and still are the
// single source of truth for role. A two-taxonomy version of this (chase_partner/chase_chaser,
// built 2026-08-27) needed a whole cross-taxonomy name-matching layer just to treat the same
// person as one profile across both; merging to one taxonomy (2026-08-29) removes the need for
// that entirely, since there's only ever one term per person now.
//
// StormChaseTemplate::save_post() (storm_chase.php) keeps this taxonomy's assignments in sync
// automatically on every save — the union of names parsed from both chasepartners and
// chasechasers, replacing (not appending to) whatever was there before, so removing a name
// from the text field also removes the stale relationship. The Settings-page scan/apply tool
// is still useful as a one-time backfill for chases saved before that auto-sync existed.
class Storm_Chases_People_Taxonomies {
    const PEOPLE_TAXONOMY = 'chase_person';

    public function __construct() {
        add_action('init', [$this, 'register_taxonomy']);

        add_action(self::PEOPLE_TAXONOMY . '_add_form_fields', [$this, 'add_person_fields']);
        add_action(self::PEOPLE_TAXONOMY . '_edit_form_fields', [$this, 'edit_person_fields']);
        add_action('created_' . self::PEOPLE_TAXONOMY, [$this, 'save_person_fields']);
        add_action('edited_' . self::PEOPLE_TAXONOMY, [$this, 'save_person_fields']);
    }

    // Parses a legacy chasepartners/chasechasers free-text value into individual names.
    // Investigated against the real dev database (benholcombdev_wp) 2026-08-27 before writing
    // this — confirmed the actual format across 434 chases is consistently comma-separated
    // names ("Bill Oosterbaan, JR Hehnly"), not free-form sentences, so a plain explode(',')
    // is the right parser rather than something more elaborate. Real data does have some rough
    // edges this can't fully clean up on its own — a few entries aren't real names ("His Dad",
    // "Every Single One of Them"), one multi-person entry wasn't comma-separated ("Bill and
    // Anna Stromberg" stays one segment), and some names vary in capitalization/formatting
    // across different chases. The scan tool (Storm_Chases_Settings) shows every parsed name
    // before anything is written, specifically so these can be caught and the source text
    // fixed by hand first if needed, rather than silently baked into terms.
    public static function parse_person_names(string $text): array {
        $text = trim($text);
        if ($text === '' || in_array(strtolower($text), ['solo', 'none'], true)) {
            return [];
        }
        $names = array_map('trim', explode(',', $text));
        return array_values(array_filter($names, fn($name) => $name !== ''));
    }

    // Manual corrections for names that are the same real person spelled/formatted
    // differently across different chases — e.g. "Dick Mcgowan" vs "Dick McGowan" (confirmed
    // 2026-08-27 to be the same person, "McGowan" the correct spelling). Deliberately a
    // human-confirmed list, not automatic fuzzy-matching: "Steve Miller (OK)" and "Steve
    // Miller (TX)" look just as similar but were confirmed to be two different people, so
    // guessing at merges from string similarity alone would be actively wrong some of the
    // time. Also doubles as a nickname-to-real-name map (e.g. "Squatch" => "Zach Wienhoff") —
    // any alias correction here takes effect for term matching immediately, no re-migration
    // needed. Stored as option 'storm_chases_person_name_aliases', array of
    // ['alias' => ..., 'canonical' => ...] pairs, editable on the Settings page. Lookup is
    // case-insensitive on the alias so "dick mcgowan"/"Dick MCGOWAN"/etc. all resolve too.
    public static function get_name_aliases(): array {
        $aliases = get_option('storm_chases_person_name_aliases', [
            ['alias' => 'Dick Mcgowan', 'canonical' => 'Dick McGowan'],
        ]);
        return is_array($aliases) ? $aliases : [];
    }

    public static function normalize_person_name(string $name): string {
        foreach (self::get_name_aliases() as $pair) {
            if (isset($pair['alias'], $pair['canonical']) && strtolower(trim($pair['alias'])) === strtolower(trim($name))) {
                return $pair['canonical'];
            }
        }
        return $name;
    }

    // Convenience combining parse_person_names() + normalize_person_name() — what save_post()'s
    // auto-sync and the migration scan/apply tool both actually use, so an alias correction
    // added on the Settings page takes effect immediately with no other code change.
    public static function parse_and_normalize_names(string $text): array {
        return array_map([self::class, 'normalize_person_name'], self::parse_person_names($text));
    }

    public function register_taxonomy(): void {
        register_taxonomy(self::PEOPLE_TAXONOMY, 'storm_chase', [
            'labels' => [
                'name' => __('Chase People', 'stormchases'),
                'singular_name' => __('Chase Person', 'stormchases'),
                'search_items' => __('Search Chase People', 'stormchases'),
                'popular_items' => __('Popular Chase People', 'stormchases'),
                'add_new_item' => __('Add New Person', 'stormchases'),
                'edit_item' => __('Edit Person', 'stormchases'),
                'not_found' => __('No chase people found.', 'stormchases'),
                'menu_name' => __('Chase People', 'stormchases'),
            ],
            'hierarchical' => false,
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_admin_column' => false,
            'show_in_rest' => true,
            'query_var' => true,
            'rewrite' => ['slug' => 'chase-person'],
        ]);
    }

    // Add/Edit Term screens gain "Website" and "Location" fields via term meta — both
    // optional, surfaced in the person's front-end modal (sc_render_person_list() in
    // functions.php). Location is deliberately free text rather than a structured
    // city/state pair — sometimes it's a whole state ("Michigan"), sometimes a city
    // ("Norman, Oklahoma"), and a single flexible field covers both without forcing a
    // format choice.
    public function add_person_fields($taxonomy): void {
        ?>
        <div class="form-field">
            <label for="sc-person-website"><?php esc_html_e('Website', 'stormchases'); ?></label>
            <input type="url" name="sc_person_website" id="sc-person-website" value="">
            <p><?php esc_html_e('Optional. If set, a link to this appears in this person\'s "other chases" popup wherever their name is mentioned.', 'stormchases'); ?></p>
        </div>
        <div class="form-field">
            <label for="sc-person-location"><?php esc_html_e('Location', 'stormchases'); ?></label>
            <input type="text" name="sc_person_location" id="sc-person-location" value="">
            <p><?php esc_html_e('Optional, free text (e.g. "Michigan" or "Norman, Oklahoma") — shown alongside their name in the "other chases" popup.', 'stormchases'); ?></p>
        </div>
        <?php
    }

    public function edit_person_fields($term): void {
        $website = get_term_meta($term->term_id, 'website', true);
        $location = get_term_meta($term->term_id, 'location', true);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="sc-person-website"><?php esc_html_e('Website', 'stormchases'); ?></label></th>
            <td>
                <input type="url" name="sc_person_website" id="sc-person-website" value="<?php echo esc_attr($website); ?>" class="regular-text">
                <p class="description"><?php esc_html_e('Optional. If set, a link to this appears in this person\'s "other chases" popup wherever their name is mentioned.', 'stormchases'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sc-person-location"><?php esc_html_e('Location', 'stormchases'); ?></label></th>
            <td>
                <input type="text" name="sc_person_location" id="sc-person-location" value="<?php echo esc_attr($location); ?>" class="regular-text">
                <p class="description"><?php esc_html_e('Optional, free text (e.g. "Michigan" or "Norman, Oklahoma") — shown alongside their name in the "other chases" popup.', 'stormchases'); ?></p>
            </td>
        </tr>
        <?php
    }

    // Hooked to created_{taxonomy}/edited_{taxonomy} (see the constructor), which WP core
    // only fires from wp_insert_term()/wp_update_term() after edit-tags.php's own
    // check_admin_referer() has already passed — no separate nonce check needed here.
    public function save_person_fields($term_id): void {
        if (!current_user_can('manage_categories')) {
            return;
        }

        if (isset($_POST['sc_person_website'])) {
            $url = esc_url_raw(trim(wp_unslash($_POST['sc_person_website'])));
            if ($url) {
                update_term_meta($term_id, 'website', $url);
            } else {
                delete_term_meta($term_id, 'website');
            }
        }

        if (isset($_POST['sc_person_location'])) {
            $location = sanitize_text_field(wp_unslash($_POST['sc_person_location']));
            if ($location !== '') {
                update_term_meta($term_id, 'location', $location);
            } else {
                delete_term_meta($term_id, 'location');
            }
        }
    }
}
