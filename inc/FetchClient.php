<?php
/**
 * Fetch API client class.
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

/**
 * Class FetchClient
 */
class FetchClient extends BaseClient {

	/**
	 * Retrieve a post
	 *
	 * @return string|WP_Error
	 */
	public function getPostByName(string $type, string $name) {
		$data = $this->call( 'GET', $this->rawfile_endpoint("_".$type."%2F".$name) );

		if ( is_wp_error( $data ) ) {
			$this->app->write_log($data);
			return $data;
		}

		//return $this->commit( $data->object->sha );
		return $data;
	}

	/**
	 * Retrieve the last commit in the repository
	 *
	 * @return Commit|WP_Error
	 */
	public function master() {
		$data = $this->call( 'GET', $this->reference_endpoint() );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		//return $this->commit( $data->object->sha );
		return $this->commit( $data->commit->id );
	}

	/**
	 * Retrieves a commit by sha from the GitHub API
	 *
	 * @param string $sha Sha for commit to retrieve.
	 *
	 * @return Commit|WP_Error
	 */
	public function commit( $sha ) {
		/*if ( $cache = $this->app->cache()->fetch_commit( $sha ) ) {
			return $cache;
	}*/

		$data = $this->call( 'GET', $this->commit_endpoint() . '/' . $sha );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$commit = new Commit( $data );
		$tree   = $this->tree_recursive( $commit->tree_sha() );

		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$commit->set_tree( $tree );

		return $this->app->cache()->set_commit( $sha, $commit );
	}

	/**
	 * Calls the content API to get the post's contents and metadata
	 *
	 * Returns Object the response from the API
	 *
	 * @param Post $post Post to retrieve remote contents for.
	 *
	 * @return mixed
	 */
	public function remote_contents( $post ) {
		return $this->call( 'GET', $this->content_endpoint() . $post->github_path() );
	}

	/**
	 * Retrieve remote tree
	 */
	public function remote_tree() {
		if (false === ($repoFiles = get_transient('pushpull_remote_repo_files'))) {
			$this->app->write_log("Fetching remote repo contents.");
			$archiveContent = $this->call( 'GET', $this->archive_endpoint() . '?sha=main' );
			if ($archiveContent === false) {
				throw new Exception("Failed to download repository archive.");
			}
			$tempArchive = tempnam(sys_get_temp_dir(), 'repo_archive_');
			file_put_contents($tempArchive, $archiveContent);
			$zip = new \ZipArchive;
			if ($zip->open($tempArchive) === TRUE) {
				$zip->extractTo(sys_get_temp_dir()); // Extract to the system temp directory
			} else {
				throw new Exception("Failed to unzip the archive.");
			}
			$repoFiles = [];
			$extractedDir = sys_get_temp_dir() . '/' . $zip->getNameIndex(0); // Get the name of the first directory
			$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extractedDir));
			foreach ($rii as $file) {
				if ($file->isDir()){
					continue;
				}
				$filePath = $file->getPathname();
				$relativePath = str_replace($extractedDir, '', $filePath);
				$hash = hash_file('sha256', $filePath);
				$repoFiles[] = [
					'path' => $relativePath,
					'checksum' => $hash
				];
			}
			unlink($tempArchive);
			array_map('unlink', glob("$extractedDir/*.*"));
			rmdir($extractedDir);
			$zip->close();

			//usort($repoFiles, function($a, $b) { return strcmp($a['path'], $b['path']); });
			// Cache results
			set_transient('pushpull_remote_repo_files', $repoFiles, 24 * HOUR_IN_SECONDS);
		}

		return $repoFiles;
	}

	/**
	 * Retrieves a tree by sha recursively from the GitHub API
	 *
	 * @param string $sha Commit sha to retrieve tree from.
	 *
	 * @return Tree|WP_Error
	 */
	protected function tree_recursive( $sha ) {
		/*if ( $cache = $this->app->cache()->fetch_tree( $sha ) ) {
			return $cache;
	}*/

		$data = $this->call( 'GET', $this->tree_endpoint() . '/' . $sha . '?recursive=1' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		foreach ( $data as $index => $thing ) {
			// We need to remove the trees because
			// the recursive tree includes both
			// the subtrees as well the subtrees' blobs.
			if ( 'tree' === $thing->type ) {
				unset( $data[ $index ] );
			}
		}

		$tree = new Tree( (object)[ 'sha'=> $sha, 'tree'=> $data ]);
		$tree->set_blobs( $this->blobs( $data ) );

		return $this->app->cache()->set_tree( $sha, $tree );
	}

	/**
	 * Generates blobs for recursive tree blob data.
	 *
	 * @param stdClass[] $blobs Array of tree blob data.
	 *
	 * @return Blob[]
	 */
	protected function blobs( array $blobs ) {
		$results = array();

		foreach ( $blobs as $blob ) {
			$obj = $this->blob( $blob );

			if ( ! is_wp_error( $obj ) ) {
				$results[] = $obj;
			}
		}

		return $results;
	}

	/**
	 * Retrieves the blob data for a given sha
	 *
	 * @param stdClass $blob Tree blob data.
	 *
	 * @return Blob|WP_Error
	 */
	protected function blob( $blob ) {
		/*if ( $cache = $this->app->cache()->fetch_blob( $blob->sha ) ) {
			return $cache;
	}*/

		$data = $this->call( 'GET', $this->blob_endpoint() . '/' . $blob->id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data->path = $blob->path;
		$obj = new Blob( $data );

		return $this->app->cache()->set_blob( $obj->sha(), $obj );
	}
}
