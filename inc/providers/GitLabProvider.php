<?php

namespace CreativeMoods\PushPull\providers;

use WP_Error;
use stdClass;

class GitLabProvider extends GitProvider implements GitProviderInterface {
	/**
	 * Generic GitHub API HEAD interface and response handler
	 *
	 * @param string $method HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param array  $body Request body.
	 *
	 * @return array|WP_Error
	 */
	protected function head( $endpoint, $body = array() ) {
		$args = array(
			'method'  => 'HEAD',
			'headers' => array(
				'PRIVATE-TOKEN' => $this->token(),
			),
			'timeout' => 30,
		);

		$response = wp_remote_head( $endpoint, $args );

		return $response;
	}

	/**
	 * GitLab API interface and response handler
	 *
	 * @param string $method HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param array  $body Request body.
	 *
	 * @return array|WP_Error
	 */
	protected function call( $method, $endpoint, $body = array(), $checkPublicRepo = true ) {
		if ( !$this->app->isPro() && $checkPublicRepo && ! $this->isPublicRepo()) {
			return new WP_Error('404', 'Connection to private repositories is not supported with this version of PushPull');
		};
		$args = array(
			'method'  => $method,
			'headers' => array(
				'PRIVATE-TOKEN' => $this->token(),
			),
			'timeout' => 30,
		);

		if ( 'GET' !== $method ) {
			$args['body'] = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		//$status   = wp_remote_retrieve_header( $response, 'status' );
		$status = $response['response']['code'];
		//$body     = json_decode( wp_remote_retrieve_body( $response ) );
		$body = json_decode($response['body']);
		if (json_last_error() != JSON_ERROR_NONE) {
			return $response['body'];
		}

		/* If not HTTP 2xx or 3xx */
		if ( '2' !== substr( $status, 0, 1 ) && '3' !== substr( $status, 0, 1 ) ) {
			return new WP_Error(
				strtolower( str_replace( ' ', '_', $status ) ),
				sprintf(
					/* translators: 1: method, 2: endpoint, 3: error */
					__( 'Method %1$s to endpoint %2$s failed with error: %3$s', 'pushpull' ),
					$method,
					$endpoint,
					$status.": ".$response['response']['message']
				)
			);
		}

		/* Check if result is paginated (recursive) */
		if ( isset( $response['headers']['link'] ) ) {
			if ( strpos( $response['headers']['link'], 'rel="next"' ) !== false ) {
				preg_match( '/<(.*)>; rel="next"/', $response['headers']['link'], $matches );
				if ( isset( $matches[1] ) ) {
					$next_page = $this->call( $method, $matches[1], $body );
					if ( ! is_wp_error( $next_page ) ) {
						$body = array_merge( $body, $next_page );
						//$body = $body + $next_page;
					}
				}
			}
		}

		return $body;
	}

	/**
	 * Checks whether a file exists in the Git repository.
	 * Issues a HEAD request to find out.
	 * We need this because we need to specify update or create in a commit.
	 *
	 * @param string $name The name of the file
	 *
	 * @return bool
	 */
	protected function git_exists( $name ) {
		$res = $this->head($this->url() . '/projects/' . urlencode($this->repository()) . '/repository/files/' . urlencode($name) . "?ref=" . $this->branch());
		return array_key_exists('response', $res) && array_key_exists('code', $res['response']) && $res['response']['code'] === 200;
	}

	/**
	 * Get a post by type and name.
	 *
	 * @return string|stdClass|WP_Error
	 */
	public function getRemotePostByName(string $type, string $name): string|stdClass|WP_Error {
		$data = $this->call( 'GET', $this->url() . '/projects/' . urlencode($this->repository()) . '/repository/files/' . "_".$type."%2F" . $name . '/raw' );

		if ( is_wp_error( $data ) ) {
			$this->app->write_log($data);
			return $data;
		}

		return $data;
	}

	/**
	 * Get posts by type.
	 *
	 * @return array
	 */
	public function getRemotePostsByType(string $type): array {
		$posts = $this->call( 'GET', $this->url() . '/projects/' . urlencode($this->repository()) . '/repository/tree?ref=' . $this->branch() . '&path=' . "_".$type );

		if ( is_wp_error( $posts ) ) {
			$this->app->write_log($posts);
			return $posts;
		}

		return $posts;
	}

	/**
	 * Delete a post by type and name.
	 *
     * @param string $type Post type.
     * @param string $name Post name.
	 * @return bool|WP_Error
	 */
    public function deleteRemotePostByName(string $type, string $name): bool|WP_Error {
		$res = $this->call( 'DELETE', $this->url() . '/projects/' . urlencode($this->repository()) . '/repository/files/' . "_".$type."%2F" . $name, ['branch' => $this->branch(), 'commit_message' => 'Deleted by PushPull'] );
		if (is_wp_error($res) || $res === false) {
			return $res;
		}

		return true;
	}

	/**
     * Initialize the repository.
     * For Gitlab we can't easily get the repository contents, so we will download the archive and extract it.
     *
     * @return array Repository details.
     */
    public function initializeRepository(): array {
        $this->app->write_log("Fetching remote repo contents.");

		// https://www.ramielcreations.com/using-streams-in-wordpress-http-requests
		// TODO replicate in other providers
        $tempArchive = tempnam(sys_get_temp_dir(), 'repo_archive_');
		$args = array(
			'method'  => 'GET',
			'headers' => array(
				'PRIVATE-TOKEN' => $this->token(),
			),
			'timeout' => 60,
			'stream' => true,
			'filename' => $tempArchive,
		);
		$response = wp_remote_request( $this->url() . '/projects/' . urlencode($this->repository()) . '/repository/archive.zip?sha=' . $this->branch(), $args );

        $zip = new \ZipArchive;
        if ($zip->open($tempArchive) === TRUE) {
            $zip->extractTo(sys_get_temp_dir()); // Extract to the system temp directory
        } else {
            throw new \Exception("Failed to unzip the archive.");
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
			/* phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents */
			$contents = file_get_contents($filePath);
            $hash = md5($contents);
            $repoFiles[$relativePath] = [
				/* TODO added for compat */
				'path' => $relativePath,
				'checksum' => $hash,
				/* end added for compat */
				'hash' => $hash,
				'updated_at' => time(),
				'status' => 'active',
			];

			// Save the file to a transient but don't create a commit since we're initializing the state
			if (strpos($relativePath, '_media/') === 0) {
				$contents = base64_encode($contents);
			}
			$this->app->state()->saveFile($relativePath, $contents);
        }
        wp_delete_file($tempArchive);
        array_map('unlink', glob("$extractedDir/*.*"));
        $zip->close();

        return $repoFiles;
    }

	/**
     * Get remote repository hashes.
     *
     * @return array|WP_Error
     */
    public function getRemoteHashes(): array|WP_Error {
		// First check if the repository exists
		$repo = $this->call( 'GET', $this->url() . '/projects/' . urlencode($this->repository()));
		if ( is_wp_error( $repo ) ) {
			$this->app->write_log($repo);
			return $repo;
		}
		// Then check if the branch exists
		$branch = $this->call( 'GET', $this->url() . '/projects/' . urlencode($this->repository()) . '/repository/branches/' . $this->branch());
		if ( is_wp_error( $branch ) ) {
			$this->app->write_log($branch);
			return $branch;
		}
		// If we now get an error it means the repository is empty
		$tree = $this->call( 'GET', $this->url() . '/projects/' . urlencode($this->repository()) . '/repository/tree?ref=' . $this->branch() . '&recursive=true' );
		if ( is_wp_error( $tree ) ) {
			return [];
		}

		$hashes = [];
		foreach ($tree as $item) {
			if ($item->type === 'blob') {
				$hashes[$item->path] = $item->id;
			}
        }

		return $hashes;
    }

	/**
	 * Get repository commits.
	 * 
	 * @return array|WP_Error
	 */
	public function getRepositoryCommits(): array|WP_Error {
		$commits = $this->call( 'GET', $this->url() . '/projects/' . urlencode($this->repository()) . '/repository/commits?all=true&ref_name=' . $this->branch() );

		if ( is_wp_error( $commits ) ) {
			$this->app->write_log($commits);
			return $commits;
		}

		return $commits;
	}

	/**
	 * Get commit details
     * @param string $commit Commit ID.
	 *
	 * @return array|WP_Error
	 */
	public function getCommitFiles(string $commit): array|WP_Error {
		$diffs = $this->call( 'GET', $this->url() . '/projects/' . urlencode($this->repository()) . '/repository/commits/' . $commit . '/diff');

		if ( is_wp_error( $diffs ) ) {
			$this->app->write_log($diffs);
			return $diffs;
		}

		return array_map(function($item) {
			return $item->new_path;
		}, $diffs);
	}

	/**
	 * Get the latest commit hash of the repository.
	 *
	 * @return string|WP_Error
	 */
	public function getLatestCommitHash(): string|WP_Error {
		$res = $this->call( 'GET', $this->url() . '/projects/' . urlencode($this->repository()) . '/repository/branches/' . $this->branch() );

		if ( is_wp_error( $res ) ) {
			$this->app->write_log($res);
			return $res;
		}

        return $res->commit->id;
    }

	/**
	 * Commit a post and its dependencies.
	 *
     * @param stdClass $wrap Commit data.
	 * @return stdClass|WP_Error
	 */
    public function commit(array $wrap): stdClass|WP_Error {
		foreach ($wrap['actions'] as $key => $action) {
			$wrap['actions'][$key]['action'] = $this->git_exists($action['file_path']) ? 'update' : 'create';
		}
		$wrap['branch'] = $this->branch();
		$res = $this->call( 'POST', $this->url() . '/projects/' . urlencode($this->repository()) . '/repository/commits', $wrap );

		return $res;
	}

	/**
	 * List branches for a repository.
	 *
	 * @param string $url
	 * @param string $token
	 * @param string $repository
	 * @return array|WP_Error
	 */
	public function getBranches(string $url, string $repository, string $token): array|WP_Error {
		$repoendpoint = $url . '/projects/' . urlencode($repository);
		$branchesendpoint = $repoendpoint . '/repository/branches';

		$response = wp_remote_request($branchesendpoint, [
			'method'  => 'GET',
			'headers' => array(
				'PRIVATE-TOKEN' => $token,
			),
			'timeout' => 30,
		]);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = $response['response']['code'];
		$branches = json_decode($response['body']);
		if (json_last_error() != JSON_ERROR_NONE) {
			return new WP_Error('404', 'Error connecting to remote git repository');
		}

		/* If not HTTP 2xx or 3xx */
		if ( '2' !== substr( $status, 0, 1 ) && '3' !== substr( $status, 0, 1 ) ) {
			return new WP_Error(
				strtolower( str_replace( ' ', '_', $status ) ),
				sprintf(
					/* translators: 1: method, 2: endpoint, 3: error */
					__( 'Method %1$s to endpoint %2$s failed with error: %3$s', 'pushpull' ),
					'GET',
					$branchesendpoint,
					$status.": ".$response['response']['message']
				)
			);
		}

		// TODO Manage pagination

		// Check if repository is public
		$response = wp_remote_request($repoendpoint, [
			'method'  => 'GET',
			'headers' => array(
				'PRIVATE-TOKEN' => $token,
			),
			'timeout' => 30,
		]);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if (!$this->app->isPro() && $response->visibility !== "public") {
				return new WP_Error('404', 'Connection to private repositories is not supported with this version of PushPull');
		}

		return $branches;
	}

	/**
	 * Is repo public ?
	 *
	 * @return bool|WP_Error
	 */
	protected function checkPublicRepo(): bool|WP_Error {
		$repo = $this->call( 'GET', $this->url() . '/projects/' . urlencode($this->repository()));
		if ( is_wp_error( $repo ) ) {
			$this->app->write_log($repo);
			return $repo;
		}

		return $repo->visibility === "public";
	}
}
