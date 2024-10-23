<?php

/**
 * Repository.
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

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
	 * Get remote repo
	 *
	 * @return array
	 */
	public function remote_tree() {
		if (false === ($repoFiles = get_transient('pushpull_remote_repo_files'))) {
			$provider = get_option($this->app::PROVIDER_OPTION_KEY);
			$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
			$repoFiles = $gitProvider->listRepository('https://github.com/example/repo.git', '/path/to/destination');
			// Cache results
			set_transient('pushpull_remote_repo_files', $repoFiles, 24 * HOUR_IN_SECONDS);
		}

		return $repoFiles;
	}

	/**
	 * Get local repo
	 *
	 * @return array
	 */
	public function local_tree() {
		$localres = [];
		$localres['media'] = [];
		foreach (get_post_types() as $posttype) {
			if (in_array($posttype, get_option($this->app::POST_TYPES_OPTION_KEY))) {
				$posts = get_posts(['numberposts' => -1, 'post_type' => 'any', 'post_type' => $posttype]);
				$localres[$posttype] = [];
				foreach ($posts as $post) {
					$content = $this->app->pusher()->create_post_export($post);
					$localres[$posttype][$post->post_name] = ['localchecksum' => hash('sha256', wp_json_encode($content)), 'remotechecksum' => null];
					// Also add media
					if (array_key_exists('meta', $content) && array_key_exists('_wp_attached_file', $content['meta'])) {
						$localres['media'][$content['meta']['_wp_attached_file']] = ['localchecksum' => hash_file('sha256', wp_upload_dir()['path']."/".$content['meta']['_wp_attached_file']), 'remotechecksum' => null];
					}
				}
			}
		}

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
		$remotefiles = $this->remote_tree();

		foreach ($remotefiles as $remotefile) {
			preg_match('/_(.+?)\/(.+)/', $remotefile['path'], $matches);
			$posttype = $matches[1];
			$postname = $matches[2];
			$checksum = $remotefile['checksum'];
			if (in_array($posttype, get_option($this->app::POST_TYPES_OPTION_KEY))) {
				if (array_key_exists($posttype, $localres) && array_key_exists($postname, $localres[$posttype])) {
					$localres[$posttype][$postname]['remotechecksum'] = $checksum;
				} else {
					if (!array_key_exists($posttype, $localres)) {
						$localres[$posttype] = [];
					}
					$localres[$posttype][$postname] = ['localchecksum' => null, 'remotechecksum' => $checksum];
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
				$res []= ['id' => $postname, 'postType' => $posttypename, 'localChecksum' => $post['localchecksum'], 'remoteChecksum' => $post['remotechecksum'], 'status' => $status];
			}
		}

		return $res;
	}
}
