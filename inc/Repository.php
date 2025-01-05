<?php

/**
 * Repository.
 * @package PushPull
 */

namespace CreativeMoods\PushPull;
use CreativeMoods\PushPull\providers\GitProviderFactory;

/**
 * Class Repository
 */
class Repository {
	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * Initializes a new repository.
	 *
	 * @param PushPull $app Application container.
	 */
	public function __construct( PushPull $app ) {
		$this->app = $app;
	}

	/**
	 * Get local repo
	 *
	 * @return array
	 */
	public function local_tree() {
		global $wpdb;

		$localres = [];
		$localres['media'] = [];
		foreach (get_post_types() as $posttype) {
			if (in_array($posttype, get_option($this->app::POST_TYPES_OPTION_KEY))) {
				$posts = get_posts(['numberposts' => -1, 'post_type' => 'any', 'post_type' => $posttype]);
				$localres[$posttype] = [];
				foreach ($posts as $post) {
					$content = $this->app->pusher()->create_post_export($post);
					$localres[$posttype][$post->post_name] = ['localchecksum' => md5(wp_json_encode($content)), 'remotechecksum' => null];
					// Also add media
					if (array_key_exists('meta', $content) && array_key_exists('_wp_attached_file', $content['meta'])) {
						$localres['media'][$content['meta']['_wp_attached_file']] = ['localchecksum' => md5(wp_upload_dir()['path']."/".$content['meta']['_wp_attached_file']), 'remotechecksum' => null];
					}
				}
			}
		}

		// Add data from plugin tables
		foreach(get_option($this->app::TABLES_OPTION_KEY, []) as $plugintable) {
			$plugin = explode('-', $plugintable)[0];
			$table = explode('-', $plugintable)[1];
			$localres[$table] = [];
			$rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table}", ARRAY_A);
			foreach ($rows as $i => $row) {
				$tablerow = $this->app->pusher()->create_tablerow_export($plugin, $table, $row);
				if ($tablerow) {
					list($keyname, $content) = $tablerow;
					$localres[$plugin.'#'.$table][$keyname] = ['localchecksum' => md5(wp_json_encode($content)), 'remotechecksum' => null];
				}
			}
		}

		// PushPull deploy script special case
		$localdeployscript = get_option('pushpull_deployscript');
		$localres['pushpull#deployscript']['Deploy Script'] = ['localchecksum' => md5($localdeployscript), 'remotechecksum' => null];
		return $localres;
	}

	/**
	 * Get both local and remote repos
	 *
	 * @return array
	 */
	public function diff_tree() {
		// Get local repo files
		$localres = $this->local_tree();
		// Get remote repo files
		$remotefiles = $this->app->state()->listFiles();
		foreach ($remotefiles as $remotefile => $filestate) {
			preg_match('/_(.+?)\/(.+)/', $remotefile, $matches);
			$posttype = $matches[1];
			$postname = str_replace('@@SLASH@@', '/', $matches[2]);
			if ($posttype === 'ppconfig' && $postname === 'deployscript') {
				// Special case for pushpull#deployscript
				$deployscript = $this->app->state()->getFile('_ppconfig/deployscript');
				$localres['pushpull#deployscript']['Deploy Script']['remotechecksum'] = md5($deployscript);
			} else if (strpos($posttype, '#' ) !== false) {
				// This is a table row
				$plugin = explode('#', $posttype)[0];
				$table = explode('#', $posttype)[1];
				if (in_array($plugin.'-'.$table, get_option($this->app::TABLES_OPTION_KEY))) {
					if (array_key_exists($posttype, $localres) && array_key_exists($postname, $localres[$posttype])) {
						$localres[$posttype][$postname]['remotechecksum'] = $filestate['hash'];
					} else {
						if (!array_key_exists($posttype, $localres)) {
							$localres[$posttype] = [];
						}
						$localres[$posttype][$postname] = ['localchecksum' => null, 'remotechecksum' => $filestate['hash']];
					}
				}
			} else {
				// This is a post type
				if (in_array($posttype, get_option($this->app::POST_TYPES_OPTION_KEY))) {
					// TODO duplicate code with above
					if (array_key_exists($posttype, $localres) && array_key_exists($postname, $localres[$posttype])) {
						$localres[$posttype][$postname]['remotechecksum'] = $filestate['hash'];
					} else {
						if (!array_key_exists($posttype, $localres)) {
							$localres[$posttype] = [];
						}
						$localres[$posttype][$postname] = ['localchecksum' => null, 'remotechecksum' => $filestate['hash']];
					}
				}
			}
		}

		// Format res for DataGrid
		$res = [];
		foreach($localres as $posttypename => $posttype) {
			foreach ($posttype as $postname => $post) {
				$status = "";
				if ($post['localchecksum'] === $post['remotechecksum']) {
					$status = 'identical';
				} elseif ($post['localchecksum'] === null) {
					$status = 'notlocal';
				} elseif ($post['remotechecksum'] === null) {
					$status = 'notremote';
				} else {
					$status = 'different';
				}
				$res []= [
					'id' => $postname,
					'postType' => $posttypename,
					'localChecksum' => $post['localchecksum'],
					'remoteChecksum' => $post['remotechecksum'],
					'status' => $status
				];
			}
		}

		return $res;
	}
}
