<?php
defined('ABSPATH') || exit;

/**
 * Offers the conversion to AI clients as a WordPress ability, `aitoel/convert-html`.
 *
 * An AI client connected to the site (Claude, Cursor and the like) then converts HTML with one call
 * where it would otherwise rebuild the page element by element. The ability reaches a client three
 * ways: on Elementor's own MCP server, where the slug is added through Elementor's filter; on the MCP
 * adapter's default server, which serves abilities marked public; and on core's REST route. Each of
 * them authenticates the caller. The ability is offered to a user who can edit posts, as the plugin's
 * page is, and it converts, as the Convert button does, only for one who may also save unfiltered HTML.
 *
 * The Abilities API is in WordPress 6.9 and later. On older versions its actions never run and nothing
 * here does anything.
 */
class HTEL_Abilities {

    const ABILITY  = 'aitoel/convert-html';
    const CATEGORY = 'aitoel';

    public static function init() {
        add_action('wp_abilities_api_categories_init', array(__CLASS__, 'register_category'));
        add_action('wp_abilities_api_init', array(__CLASS__, 'register'));
        add_filter('elementor/mcp/server/tools', array(__CLASS__, 'list_on_elementor_server'));
    }

    /**
     * A site owner switches the ability off with `add_filter( 'aitoel_abilities_enabled', '__return_false' )`.
     */
    public static function enabled() {
        return (bool) apply_filters('aitoel_abilities_enabled', true);
    }

    /**
     * The four functions used below. Core has them from 6.9; a site may also run the Abilities API as a
     * plugin, in a version that fires these actions and lacks one of them.
     */
    private static function available() {
        return function_exists('wp_register_ability') && function_exists('wp_has_ability')
            && function_exists('wp_register_ability_category') && function_exists('wp_has_ability_category');
    }

    public static function register_category() {
        if (!self::enabled() || !self::available() || wp_has_ability_category(self::CATEGORY)) {
            return;
        }
        wp_register_ability_category(self::CATEGORY, array(
            'label'       => __('AI to Elementor', 'aitoel-html-importer'),
            'description' => __('Import existing HTML as native Elementor templates.', 'aitoel-html-importer'),
        ));
    }

    public static function register() {
        if (!self::enabled() || !self::available() || wp_has_ability(self::ABILITY)) {
            return;
        }
        wp_register_ability(self::ABILITY, array(
            'label'       => __('Convert HTML to an Elementor template', 'aitoel-html-importer'),
            'description' => __('Converts a complete HTML page, its CSS included, into a native, editable Elementor template in one call and saves it in this site\'s Elementor template library. Use it whenever the page already exists as HTML, instead of rebuilding it element by element. Pass the full HTML document, or the public URL of the page. Returns the template\'s id and its edit URL; the template can then be inserted into any page from the Elementor library. Each call counts as one conversion of the site\'s AI to Elementor allowance.', 'aitoel-html-importer'),
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'html' => array(
                        'type'        => 'string',
                        'description' => 'The full HTML document, CSS included, or the public URL of the page.',
                    ),
                    'title' => array(
                        'type'        => 'string',
                        'description' => 'A title for the saved template. Defaults to "Imported Page".',
                    ),
                    'intent' => array(
                        'type'        => 'string',
                        'enum'        => array('header', 'footer'),
                        'description' => 'Set only when the HTML is a site header or a site footer rather than a page.',
                    ),
                ),
                'required' => array('html'),
            ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'template_id'   => array('type' => 'integer', 'description' => 'The saved template\'s post id.'),
                    'edit_url'      => array('type' => 'string', 'description' => 'Opens the template in the Elementor editor.'),
                    'library_url'   => array('type' => 'string', 'description' => 'The site\'s Elementor template library.'),
                    'element_count' => array('type' => 'integer', 'description' => 'Elementor elements in the template.'),
                    'message'       => array('type' => 'string'),
                ),
            ),
            'permission_callback' => array(__CLASS__, 'may_convert'),
            'execute_callback'    => array('HTEL_Converter', 'ability_convert'),
            'meta' => array(
                'show_in_rest' => true,
                'mcp'          => array('public' => true),
                'annotations'  => array('readonly' => false, 'idempotent' => false, 'destructive' => false),
            ),
        ));
    }

    /**
     * The capability the plugin's own page and its Convert button ask for. The conversion then asks for
     * the right to save unfiltered HTML and says so when it is missing, which a refusal here could not.
     */
    public static function may_convert() {
        return current_user_can('edit_posts');
    }

    /**
     * Elementor builds its MCP server from a list of ability slugs and passes that list through this
     * filter for other plugins to add to.
     *
     * @param mixed $tools  The slugs so far.
     * @return array
     */
    public static function list_on_elementor_server($tools) {
        $tools = is_array($tools) ? $tools : array();
        if (self::enabled() && !in_array(self::ABILITY, $tools, true)) {
            $tools[] = self::ABILITY;
        }
        return $tools;
    }
}
