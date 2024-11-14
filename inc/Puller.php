<?php
/**
 * GitHub Import Manager
 *
 * @package PushPull
 */

namespace CreativeMoods\PushPull;
use CreativeMoods\PushPull\providers\GitProviderFactory;

/**
 * Class Import
 */
class Puller {
	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * Initializes a new import manager.
	 *
	 * @param PushPull $app Application container.
	 */
	public function __construct( PushPull $app ) {
		$this->app = $app;
	}

	/**
	 * Import an image.
	 *
	 * @param string $image the name of the image.
	 *
	 * @return integer|\WP_Error
	 */
	protected function import_image($image) {
		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);

		// Get attachment from Git
		$imagepost = $gitProvider->getRemotePostByName('attachment', $image);
		// Find local filename
		$fn = wp_upload_dir()['path']."/".$imagepost->meta->_wp_attached_file[0];
		// Get binary contents from Git
		$media = $gitProvider->getRemotePostByName('media', $imagepost->meta->_wp_attached_file[0]);
		// Write binary contents to local file in uploads/
		$wpfsd = new \WP_Filesystem_Direct( false );
		// TODO check result
		$wpfsd->put_contents ( $fn, $media );
		// Create attachment
		$foundimage = $this->app->utils()->getLocalPostByName('attachment', $image);
		if ($foundimage !== null) {
			$this->app->write_log(
				sprintf(
					/* translators: 1: image name, 2: filename */
					__( 'Image attachment %1$s (%2$s) already exists locally. Updating.', 'pushpull' ),
					$image,
					$fn,
				)
			);
			return $foundimage->ID;
		} else {
			$this->app->write_log(__( 'Creating new image attachment.', 'pushpull' ));
			$wp_filetype = wp_check_filetype($fn, null);
			$return = apply_filters( 'wp_handle_upload', array( 'file' => $fn, 'url' => wp_upload_dir()['url'].'/'.$imagepost->meta->_wp_attached_file[0], 'type' => $wp_filetype['type'] ) );
			$attachment = [
				'post_mime_type' => $return['type'],
				'guid'           => $return['url'],
				'post_parent'    => 0,
				'post_title'     => $imagepost->post_title,
				'post_name'      => $imagepost->post_name,
				'post_content'   => str_replace("@@DOMAIN@@", get_home_url(), $imagepost->post_content),
				'post_excerpt'   => property_exists($imagepost, 'post_excerpt') ? $imagepost->post_excerpt : "",
				'post_date'      => $imagepost->post_date,
				'post_date_gmt'  => property_exists($imagepost, 'post_date_gmt') ? $imagepost->post_date_gmt : $imagepost->post_date,
			];
			$attachid = wp_insert_attachment($attachment, $fn, 0);
			// Regenerate attachment metadata
			$data = wp_generate_attachment_metadata($attachid, $fn);
			wp_update_attachment_metadata($attachid, $data);
			// TODO Quid de postimage->meta['_wp_attachment_image_alt'] ?
			// Move to original folder
			if (function_exists('wp_rml_move') && property_exists($imagepost, 'folder')) {
				wp_rml_move(wp_rml_create_or_return_existing_id($imagepost->folder, _wp_rml_root(), 0, [], false, true), [$attachid]);
			}
			return $attachid;
		}
	}

	/**
	 * Pull all posts of a specific type.
	 *
	 * @param string $type
	 * @return string|WP_Error
	 */
	public function pullall($type) {
		$this->app->write_log(
			sprintf(
				/* translators: 1: type of post */
				__( 'Starting pull from Git for all %1$s.', 'pushpull' ),
				$type,
			)
		);

		// Get all files in remote repository in directory _$type
		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
		$posts = $gitProvider->getRemotePostsByType($type);
		foreach ($posts as $post) {
			$this->pull($type, $post->name);
		}

		$this->app->write_log(
			sprintf(
				/* translators: 1: id of post */
				__( 'End pull from Git for all %1$s.', 'pushpull' ),
				$type,
			)
		);
	}

	/**
	 * Pull a post.
	 *
	 * @param string $type the type of post.
	 * @param string $name the name of the post.
	 *
	 * @return string|WP_Error
	 */
	public function pull( $type, $name ) {
		$this->app->write_log(
			sprintf(
				/* translators: 1: name of post */
				__( 'Starting pull from Git for %1$s.', 'pushpull' ),
				$name,
			)
		);

		// TODO Est-ce qu'on peut éviter d'utiliser la factory plusieurs fois ?
		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
		$post = $gitProvider->getRemotePostByName($type, $name);
		// TODO We need to add wp_slash otherwise \\ will be deleted -> too many slashes ?
		$post->post_content = str_replace("@@DOMAIN@@", get_home_url(), wp_slash($post->post_content));

		// Handle author (otherwise will be admin)
		if (property_exists($post, 'author')) {
			if (!$post->author) {
				$post->author = 'admin';
			}
			$user = get_user_by('login', $post->author);
			$post->post_author = $user->ID;
		}

		// Post
		$localpost = $this->app->utils()->getLocalPostByName($type, $name);
		if ($localpost !== null) {
			$this->app->write_log(__( 'Post already exists locally. Deleting.', 'pushpull' ));
			wp_delete_post($localpost->ID, true);
		}
		$this->app->write_log(__( 'Creating new post.', 'pushpull' ));
		$subpost = $this->app->utils()->sub_array((array)$post, [
			'post_title',
			'post_name',
			'post_content',
			'post_password',
			'post_excerpt',
			'post_date',
			'post_date_gmt',
			'post_status',
			'post_type',
			'post_author'
		]);
		$id = wp_insert_post($subpost, true);
		if (is_wp_error($id)) {
			$this->app->write_log(__( 'Error creating post.', 'pushpull' ));
		}
		$post->ID = $id;

		// Post terms
		if (property_exists($post, 'terms')) {
			foreach ($post->terms as $taxonomy => $terms) {
				// Remove all terms since we're appending
				wp_set_object_terms($id, [], $taxonomy, false);
				foreach ($terms as $term) {
					$this->app->write_log(
						sprintf(
							/* translators: 1: taxonomy of the term */
							__( 'Creating term for taxonomy %1$s.', 'pushpull' ),
							$taxonomy,
						)
					);
					$term_obj = get_term_by('slug', $term->slug, $taxonomy);
					if ($term_obj) {
						$termid = $term_obj->term_id;
					} else {
						// TODO Handle parent
						$termid = wp_insert_term($term->name, $taxonomy, ['slug' => $term->slug])['term_id'];
					}
					wp_set_post_terms($id, [$termid], $taxonomy, true);
				}
			}
		}

		// Post images
		if (property_exists($post, 'featuredimage')) {
			$imageid = $this->import_image($post->featuredimage);
			update_post_meta($id, '_thumbnail_id', $imageid);
		}
		if (property_exists($post, 'intimages')) {
			foreach ($post->intimages as $image) {
				$this->import_image($image);
			}
		}

		// First we import all meta data
		// If you are a plugin maintainer and need to modify a meta value,
		// do so in the pushpull_import_yourplugin action hook and overwrite what is created here.
		// If you need to remove a meta key you can do so when pushing the data to Git.
		if (property_exists($post, 'meta')) {
			foreach ($post->meta as $key => $value) {
				if ($key === "_edit_last" and is_string($value)) {
					// We need to find the user ID from the login
					$user = get_user_by('login', $value);
					if ($user) {
						$this->app->write_log("Setting meta: ".$user->ID);
						add_post_meta($id, $key, $user->ID);
					}
					continue;
				}
				foreach ($value as $v) {
					// We need to maybe_unserialize ourselves because add_post_meta will serialize
					//$this->app->write_log("Adding meta key ".$key." with value ".$v);
					add_post_meta($id, $key, maybe_unserialize($v));
				}
			}
		}

		// We find out registered plugins and apply one action for each.
		// If you are a plugin maintainer and want PushPull to handle your
		// data, create an action hook named pushpull_import_yourplugin
		$active_plugins = get_option('active_plugins');
		foreach ($active_plugins as $plugin) {
			$plugin = explode("/", $plugin)[0];
			if (has_action('pushpull_import_' . $plugin)) {
				// We call the 3rd party filter hook if it exists
				do_action('pushpull_import_' . $plugin, $post);
			} else {
				// Call our default filter hook for this 3rd party plugin
				do_action('pushpull_default_import_' . $plugin, $post);
			}
		}

		$this->app->write_log(
			sprintf(
				/* translators: 1: id of post */
				__( 'End import from Git with id %1$s.', 'pushpull' ),
				$id,
			)
		);

		return $id;
	}
}
