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
	 * Push changes to repo.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|array
	 */
	public function repopush() {
		$localLatestCommitHash = $this->app->state()->getLatestCommitHash();
		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
		$remoteLatestCommitHash = $gitProvider->getLatestCommitHash();

		// Get list of commits from the commit log
		$commits = $this->app->state()->getCommitLog();

		// Get commits from $remoteLatestCommitHash to $localLatestCommitHash
		// TODO this will not work when there's no commit in the remote repository
		$commits = $this->app->utils()->getElementsBetweenIds($commits, $remoteLatestCommitHash, $localLatestCommitHash);

		$actions = [];
		foreach ($commits as $commit) {
			foreach ($commit['changes'] as $filePath => $hash) {
				$actions[] = [
					'action' => 'tbd',
					'file_path' => $filePath,
					'content' => $this->app->state()->getFile($filePath),
				];
			}
			$user = get_userdata(get_current_user_id());
			$wrap = [
				'branch' => 'tbd', // Will be filled in later in the provider
				'commit_message' => $commit['message'],
				'actions' => $actions,
				'author_email' => $user->user_email,
				'author_name' => $user->display_name,
			];

			$provider = get_option($this->app::PROVIDER_OPTION_KEY);
			$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
			$res = $gitProvider->commit($wrap);
			if (is_wp_error($res)) {
				$this->app->write_log($res);
				return $res;
			}
			$this->app->state()->updateCommitId($commit['id'], $res->id);
		}

		return ['result' => 'success'];
	}

	/**
	 * Pull changes from repo.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|array
	 */
	public function repopull() {
		if ($this->app->state()->getLatestCommitHash()) {
			// If we have a local commit hash, we use it to get the missing commits
			$this->app->write_log('Getting diff from latest commit hash: '.$this->app->state()->getLatestCommitHash());
			$provider = get_option($this->app::PROVIDER_OPTION_KEY);
			$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
			$localLatestCommitHash = $this->app->state()->getLatestCommitHash();
			$remoteLatestCommitHash = $gitProvider->getLatestCommitHash();
			$commits = $gitProvider->getRepositoryCommits();
			$commits = $this->app->utils()->getElementsBetweenIds($commits, $localLatestCommitHash, $remoteLatestCommitHash);
			foreach ($commits as $commit) {
				$files = $gitProvider->getCommitFiles($commit->id);
				foreach ($files as $file) {
					list($type, $name) = explode('/', $file);
					$content = $gitProvider->getRemotePostByName(ltrim($type, '_'), $name);
					$content = json_decode(base64_decode($content->content));
					if ($type === '_media') {
						// TODO test this and test both with gitlab
						$this->app->state()->saveFile($file, $content);
					} else {
						$this->app->state()->saveFile($file, wp_json_encode($content));
					}
				}
			}
			$this->app->state()->importcommits($commits, false);
		} else {
			// Otherwise, we need to get the remote hashes and initialize our local state with the latest commit hash from remote
			$this->app->write_log('Getting remote hashes');
			$provider = get_option($this->app::PROVIDER_OPTION_KEY);
			$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
			$remotehashes = $gitProvider->getRemoteHashes();
			if (is_wp_error($remotehashes)) {
				return $remotehashes;
			}
			// Initialize the state but don't create commits
			$repofiles = $gitProvider->initializeRepository();
			// Save state
			$this->app->state()->saveState($repofiles);

			// Get all remote commits and push them onto the local commit log
			$commits = $gitProvider->getRepositoryCommits();
			$this->app->state()->importcommits($commits, true);
		}
		$this->app->state()->saveLatestCommitHash($gitProvider->getLatestCommitHash());

		return ['result' => 'success'];
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
			$localres[$table] = []; // TODO
			$tablename = esc_sql($table);
		    /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
			$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}{$tablename}"), ARRAY_A);
			foreach ($rows as $i => $row) {
				$tablerow = $this->app->pusher()->create_tablerow_export($plugin, $table, $row);
				if ($tablerow) {
					list($keyname, $content) = $tablerow;
					$localres[$plugin.'@'.$table][$keyname] = ['localchecksum' => md5(wp_json_encode($content)), 'remotechecksum' => null];
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
		$remotefiles = $this->app->state()->listFiles();
		foreach ($remotefiles as $remotefile => $filestate) {
			preg_match('/_(.+?)\/(.+)/', $remotefile, $matches);
			$posttype = $matches[1];
			$postname = str_replace('@@SLASH@@', '/', $matches[2]);
			if (strpos($posttype, '@' ) !== false) {
				// This is a table row
				$plugin = explode('@', $posttype)[0];
				$table = explode('@', $posttype)[1];
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
