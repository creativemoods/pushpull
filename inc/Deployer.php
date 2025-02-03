<?php
/**
 * Deployer.
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

/**
 * Class Deployer
 */
class Deployer {
	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * Initializes a new deployer.
	 *
	 * @param PushPull $app Application container.
	 */
	public function __construct( PushPull $app ) {
		$this->app = $app;
	}

	/**
	 * Get a value from the database.
	 *
	 * @param string $type - the type of the value.
	 * @param string $name - the name of the value.
	 * 
	 * @return mixed
	 */
	public function getValue($type, $name, $curval = null) {
		switch ($type) {
			case 'option_set':
				return get_option($name);
			case 'option_setidfromname':
				$post = get_post(get_option($name));
				if ($post) {
					return $post->post_name;
				} else {
					return "unknown";
				}
			case 'option_setserialized':
				return maybe_serialize(get_option($name));
			case 'option_mergejson':
				if ($curval) {
					// We need to test the merge to see if the value has already been merged
					$option = json_decode(get_option($name));
					$value = json_decode($curval);
					$merged = (object) array_merge((array) $option, (array) $value);
					if (wp_json_encode($merged) === get_option($name)) {
						return $curval;
					} else {
						return "";
					}
				} else {
					return "";
				}
			default:
				return "unknown";
		}
	}

	/**
	 * Deploy configuration and contents.
	 *
	 * @param int $id the id if the deploy item.
	 * 
	 * @return bool — The result.
	 */
	public function deploy($id): bool {
		global $wpdb;

		// Define the table name
		$table_name = esc_sql($wpdb->prefix . $this->app::PP_DEPLOY_TABLE);

        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
		$deployitem = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id));
		switch ($deployitem->type) {
			case 'option_set':
				return update_option($deployitem->name, $deployitem->value);
			case 'option_setidfromname':
				$post = $this->app->utils()->getLocalPostByName('page', $deployitem->value);
				if (!$post) {
					// TODO not clean
					$post = $this->app->utils()->getLocalPostByName('attachment', $deployitem->value);
				}
				if ($post) {
					return update_option($deployitem->name, $post->ID);
				} else {
					return false;
				}
			case 'option_setserialized':
				update_option($deployitem->name, maybe_unserialize($deployitem->value));
				break;
			case 'option_mergejson':
				$option = json_decode(get_option($deployitem->name));
				$value = json_decode($deployitem->value);
				$merged = (object) array_merge((array) $option, (array) $value);
				update_option($deployitem->name, wp_json_encode($merged));
				break;
			case 'pushpull_pull':
				list ($type, $name) = explode('/', $deployitem->name);
				$this->app->puller()->pull($type, $name);
				break;
			default:
				return false;
		}

		return false;
	}
}
