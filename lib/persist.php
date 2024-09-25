<?php
/**
 * API Persist client.
 * @package PushPull
 */

require_once __DIR__ . '/base.php';

/**
 * Class PushPull_Persist_Client
 */
class PushPull_Persist_Client extends PushPull_Base_Client {

	/**
	 * Add a new commit to the master branch.
	 *
	 * @param PushPull_Commit $commit Commit to create.
	 *
	 * @return bool|mixed|PushPull_Commit|WP_Error
	 */
	public function commit( WP_Post $post ) {
		$this->app->write_log(__( 'Starting export to Git.', 'pushpull' ));
		// Handle post images
		$imageids = $this->extract_imageids($post);
		foreach ($imageids as $imageid) {
			if ($imageid['id']) {
				$image = get_post($imageid['id']);
				$commitres = $this->create_commit($image);
				if ( is_wp_error( $commitres ) ) {
					$this->app->write_log($commitres);
					return $commitres;
				}
			}
		}

		// Handle featured image
		$meta = get_post_meta($post->ID);
		if (array_key_exists('_thumbnail_id', $meta)) {
			$image = get_post($meta['_thumbnail_id'][0]);
			$commitres = $this->create_commit($image);
			if ( is_wp_error( $commitres ) ) {
				$this->app->write_log($commitres);
				return $commitres;
			}
		}

		// Handle post
		$commitres = $this->create_commit($post);
		if ( is_wp_error( $commitres ) ) {
			$this->app->write_log($commitres);
			return $commitres;
		}

		$this->app->write_log(__( 'End export to Git.', 'pushpull' ));
		delete_transient('pushpull_remote_repo_files');
		return true;
	}

	/**
	 * Create the tree by a set of blob ids.
	 *
	 * @param PushPull_Tree $tree Tree to create.
	 *
	 * @return stdClass|WP_Error
	 */
	protected function create_tree( PushPull_Tree $tree ) {
		return $this->call( 'POST', $this->tree_endpoint(), $tree->to_body() );
	}

	// retrieves the attachment ID from the file URL
	// https://wordpress.stackexchange.com/questions/377301/how-do-you-find-a-file-in-the-media-library-using-the-file-url
	function get_image_id($image_url) {
		global $wpdb;
		$image_url = preg_replace("(^https?://)", "", $image_url);
		$the_attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid LIKE %s;", '%'.$image_url ));
		return empty($the_attachment) ? null : $the_attachment[0];
	}

	/**
	 * Get local repo
	 */
	public function local_tree() {
		$localres = [];
		$localres['media'] = [];
		foreach (get_post_types() as $posttype) {
			if (in_array($posttype, ['attachment', 'gp_elements', 'media', 'page', 'post'])) {
				$posts = get_posts(['numberposts' => -1, 'post_type' => 'any', 'post_type' => $posttype]);
				$localres[$posttype] = [];
				foreach ($posts as $post) {
					$content = $this->create_post_export($post);
					$localres[$posttype][$post->post_name] = ['localchecksum' => hash('sha256', json_encode($content)), 'remotechecksum' => null];
					// Also add media
					if (array_key_exists('meta', $content) && array_key_exists('_wp_attached_file', $content['meta'])) {
						$localres['media'][$content['meta']['_wp_attached_file']] = ['localchecksum' => hash_file('sha256', wp_upload_dir()['path']."/".$content['meta']['_wp_attached_file']), 'remotechecksum' => null];
					}
				}
			}
		}

		// Get remote repo files
		// TODO remonter fetch()
		$remotefiles = $this->app->api()->fetch()->remote_tree();
		foreach ($remotefiles as $remotefile) {
			preg_match('/_(.+?)\/(.+)/', $remotefile['path'], $matches);
			$posttype = $matches[1];
			$postname = $matches[2];
			$checksum = $remotefile['checksum'];
			if (array_key_exists($posttype, $localres) && array_key_exists($postname, $localres[$posttype])) {
				$localres[$posttype][$postname]['remotechecksum'] = $checksum;
			} else {
				if (!array_key_exists($posttype, $localres)) {
					$localres[$posttype] = [];
				}
				$localres[$posttype][$postname] = ['localchecksum' => null, 'remotechecksum' => $checksum];
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

	/**
	 * Find images in blocks and replace
	 */
	protected function convert_bgImage_urls_to_uppercase($blocks) {
		// Iterate through all the blocks
		foreach ($blocks as &$block) {
			// Check if the block has attributes
			if (isset($block['attrs'])) {
				// Check if 'bgImage' attribute exists
				if (isset($block['attrs']['bgImage'])) {
					// Check if 'image' property exists in 'bgImage'
					if (isset($block['attrs']['bgImage']['image'])) {
						// Check if 'url' property exists in 'image'
						if (isset($block['attrs']['bgImage']['image']['url'])) {
							// Convert the URL to uppercase
							$block['attrs']['bgImage']['image']['url'] = str_replace(get_home_url(), "@@DOMAIN@@", $block['attrs']['bgImage']['image']['url']);
						}
					}
				}
			}

			// If the block has inner blocks, apply the function recursively
			if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$block['innerBlocks'] = $this->convert_bgImage_urls_to_uppercase($block['innerBlocks']);
			}
		}

		return $blocks;
	}

	/**
	 * Create post export
	 *
	 * @param WP_Post
	 *
	 */
	public function create_post_export(WP_Post $post) {
		$data = [];
		$data['post_content'] = str_replace(get_home_url(), "@@DOMAIN@@", $post->post_content);
		$data['post_type'] = $post->post_type;
		$data['post_status'] = $post->post_status;
		$data['post_name'] = $post->post_name;
		$data['post_title'] = $post->post_title;
		$data['post_password'] = $post->post_password;
		$data['post_date'] = $post->post_date;
		$data['post_date_gmt'] = $post->post_date_gmt;

		// Meta
		$meta = [];
		foreach (get_post_meta($post->ID) as $key => $value) {
			if ($key === "_edit_lock") {
				continue;
			}
			if ($key === "_thumbnail_id") {
				$image = get_post($value[0]);
				$data['featuredimage'] = $image->post_name;
				continue;
			}
			if ($key === "_generate_element_display_conditions") {
				$unserialized = maybe_unserialize($value[0]);
				foreach ($unserialized as $item => $displaycond) {
					if ($displaycond['rule'] === "post:page") {
						$tmppost = get_post($displaycond['object']);
						if ($tmppost) {
							$unserialized[$item]['object'] = $tmppost->post_type."/".$tmppost->post_name;
						}
					}
				}
				$meta[$key] = maybe_serialize($unserialized);
				continue;
			}
			$meta[$key] = $value[0];
		}
		$data['meta'] = $meta;
		$taxonomies = get_object_taxonomies($post->post_type);
		if (!empty($taxonomies)) {
			$terms = wp_get_object_terms($post->ID, $taxonomies);
			foreach ((array)$terms as $i => $term) {
				// Rewrite post IDs into post names for polylang post_translations taxonomies
				if ($term->taxonomy === 'post_translations') {
					$newvals = [];
					$description = maybe_unserialize($term->description);
					foreach($description as $lang => $id) {
						$tmppost = get_post($id);
						$newvals[$lang] = $tmppost->post_type."/".$tmppost->post_name;
					}
					$data['translations'] = maybe_serialize($newvals);
				} elseif ($term->taxonomy === 'language') {
					$data['language'] = $term->slug;
				} else {
					$data['terms'][] = $term;
				}
			}
		} else {
			$data['terms'] = [];
		}

		// If we have a media folder plugin, add location
		if ($post->post_type === "attachment" && function_exists('wp_attachment_folder')) {
			$folder = wp_rml_get_by_id(wp_attachment_folder($post->ID));
			if (is_rml_folder($folder)) {
				$data['folder'] = $folder->getName();
			}
		}

		// Handle post images
		$imageids = $this->extract_imageids($post);
		$intimagelist = [];
		$extimagelist = [];
		foreach ($imageids as $imageid) {
			if ($imageid['id']) {
				$image = get_post($imageid['id']);
				$intimagelist[] = $image->post_name;
			} else {
				$extimagelist[] = $imageid['url'];
			}
		}
		$data['intimages'] = $intimagelist;
		$data['extimages'] = $extimagelist;

		return $data;
	}

	/**
	 * Find images in blocks code
	 */
	protected function find_bg_image_urls($blocks) {
		$urls = [];
		foreach ($blocks as $block) {
			// Check if the block has attributes and a bgImage attribute
			if (isset($block['attrs']) && isset($block['attrs']['bgImage'])) {
				$bgImage = $block['attrs']['bgImage'];
				// Check if bgImage has an image with a URL
				if (isset($bgImage['image']['url'])) {
					$urls[] = $bgImage['image']['url'];
				}
			}
			// Recursively search in innerBlocks if they exist
			if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$innerUrls = $this->find_bg_image_urls($block['innerBlocks']);
				$urls = array_merge($urls, $innerUrls);
			}
		}

		return $urls;
	}

	/**
	 * Export post images that are in the media library
	 *
	 * @param WP_Post
	 *
	 */
	protected function extract_imageids(WP_Post $post) {
		$data = [];
		$document = new DOMDocument();
		libxml_use_internal_errors(true);
		if ($post->post_content === "") {
			return $data;
		}
		$document->loadHTML($post->post_content);
		$images = $document->getElementsByTagName('img');
		foreach ($images as $image) {
			$url = $image->getAttribute('src');
			$data[] = ['id' => $this->get_image_id($url), 'url' => $url];
		}

		// Also look for images in blocks code
		$images = $this->find_bg_image_urls(parse_blocks($post->post_content));
		foreach ($images as $image) {
			$data[] = ['id' => $this->get_image_id($image), 'url' => $image];
		}

		return $data;
	}

	/**
	 * Checks whether a file exists in the Git repository
	 *
	 * @param string $name The name of the file
	 *
	 * @return bool
	 */
	protected function git_exists( $name ) {
		$res = $this->head( $this->file_endpoint($name) );
		return array_key_exists('response', $res) && array_key_exists('code', $res['response']) && $res['response']['code'] === 200;
	}

	/**
	 * Create the commit from tree sha.
	 *
	 * @param PushPull_Commit $commit Commit to create.
	 *
	 * @return mixed
	 */
	protected function create_commit(WP_Post $post) {
		$author = $this->export_user();
		$content = $this->create_post_export($post);
		$files = [];
		if (array_key_exists('meta', $content) && array_key_exists('_wp_attached_file', $content['meta'])) {
			// This is an attachment that references a file in uploads, we need to add it
			$fn = wp_upload_dir()['path']."/".$content['meta']['_wp_attached_file'];
			$fh = fopen($fn, 'r');
			$fc = fread($fh, filesize($fn));
			fclose($fh);
			$files[] = [
				'action' => $this->git_exists("_media%2F".$content['meta']['_wp_attached_file']) ? 'update' : 'create',
				'file_path' => "_media/".$content['meta']['_wp_attached_file'],
				'content' => base64_encode($fc),
				'encoding' => "base64",
			];
		}
		$wrap = [
			'branch' => 'main',
			'commit_message' => "PushPull Git export single post",
			'actions' => array_merge([[
				'action' => $this->git_exists("_".$post->post_type."%2F".$post->post_name) ? 'update' : 'create',
				'file_path' => "_".$post->post_type."/".$post->post_name,
				'content' => json_encode($content),
			]], $files),
			'author_email' => $author['email'],
			'author_name' => $author['name'],
		];
		$res = $this->call( 'POST', $this->commit_endpoint(), $wrap );
		return $res;
	}

	/**
	 * Updates the master branch to point to the new commit
	 *
	 * @param string $sha Sha for the commit for the master branch.
	 *
	 * @return mixed
	 */
	protected function set_ref( $sha ) {
		return $this->call( 'PATCH', $this->reference_endpoint(), array( 'sha' => $sha ) );
	}

	/**
	 * Get the data for the current user.
	 *
	 * @return array
	 */
	protected function export_user() {
		// @todo constant/abstract out?
		if ( $user_id = (int) get_option( '_wpghs_export_user_id' ) ) {
			delete_option( '_wpghs_export_user_id' );
		} else {
			$user_id = get_current_user_id();
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			// @todo is this what we want to include here?
			return array(
				'name'  => 'Anonymous',
				'email' => 'anonymous@users.noreply.github.com',
			);
		}

		return array(
			'name'  => $user->display_name,
			'email' => $user->user_email,
		);
	}
}
