<?php

namespace CreativeMoods\PushPull\providers;

use WP_Error;
use stdClass;

class GitHubProvider extends GitProvider implements GitProviderInterface {
	/**
	 * GitHub API interface and response handler
	 *
	 * @param string $method HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param array  $body Request body.
	 *
	 * @return array|WP_Error
	 */
	protected function call( $method, $endpoint, $body = array(), $checkPublicRepo = true ) {
		if ( $checkPublicRepo && ! $this->isPublicRepo()) {
			return new WP_Error('404', 'Connection to private repositories is not supported with this version of PushPull');
		};
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer '.$this->token(),
				'X-GitHub-Api-Version' => '2022-11-28',
				'Accept' => 'application/vnd.github+json',
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
	 * We need this because we need to specify update or create in a commit.
	 *
	 * @param string $name The name of the file
	 *
	 * @return string|null
	 */
	protected function git_exists( $name ) {
		$res = $this->call('GET', $this->url() . '/repos/' . $this->repository() . '/contents/' . $name);
		if (is_wp_error($res)) {
			return null;
		}

		return $res->sha ?? null;
	}

	/**
	 * Get a post by type and name.
	 *
	 * @return string|stdClass|WP_Error
	 */
	public function getRemotePostByName(string $type, string $name): string|stdClass|WP_Error {
		$data = $this->call( 'GET', $this->url() . '/repos/' . $this->repository() . '/contents/' . "_" . $type . "/" . $name );

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
		// TODO Implement, this is for gitlab
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
		// First get SHA
		$sha = $this->git_exists("_" . $type . "/" . $name);
		// TODO Handle error
		$res = $this->call( 'DELETE', $this->url() . '/repos/' . $this->repository() . '/contents/' . "_" . $type . "/" . $name, ['message' => 'Deleted by PushPull', 'sha' => $sha] );
		if (is_wp_error($res) || $res === false) {
			return $res;
		}

		return true;
	}

	/**
	 * Commit a post and its dependencies.
	 *
     * @param stdClass $wrap Commit data.
	 * @return stdClass|WP_Error
	 */
    public function commit(array $wrap): stdClass|WP_Error {
		$latestcommithash = $this->getLatestCommitHash();

		// Create a blob for each $wrap action
		foreach ($wrap['actions'] as $key => $action) {
//			$wrap['actions'][$key]['sha'] = $this->git_exists($action['file_path']);
//			if ($wrap['actions'][$key]['sha'] === null) {
				$res = $this->call( 'POST', $this->url() . '/repos/' . $this->repository() . '/git/blobs', ['encoding' => 'utf-8', 'content' => $action['content']] );
				if (is_wp_error($res)) {
					$this->app->write_log($res);
					return $res;
				}
				$wrap['actions'][$key]['sha'] = $res->sha;
//			}
		}

		// Get the current base tree
		$res = $this->call( 'GET', $this->url() . '/repos/' . $this->repository() . '/git/commits/' . $latestcommithash );
		$base_tree = $res->tree->sha;

		// Create a tree referencing the blobs
		$tree = [];
		foreach ($wrap['actions'] as $action) {
			$tree[] = [
				'path' => $action['file_path'],
				'mode' => '100644',
				'type' => 'blob',
				'sha' => $action['sha'],
			];
		}
		$res = $this->call( 'POST', $this->url() . '/repos/' . $this->repository() . '/git/trees', ['base_tree' => $base_tree, 'tree' => $tree] );
		if ( is_wp_error( $res ) ) {
			$this->app->write_log($res);
			return $res;
		}

		// Create a commit referencing the tree
		$res = $this->call( 'POST', $this->url() . '/repos/' . $this->repository() . '/git/commits', ['message' => $wrap['commit_message'], 'tree' => $res->sha, 'parents' => [$latestcommithash]] );
		if ( is_wp_error( $res ) ) {
			$this->app->write_log($res);
			return $res;
		}

		// Update the branch reference
		$res = $this->call( 'PATCH', $this->url() . '/repos/' . $this->repository() . '/git/refs/heads/' . $this->branch(), ['sha' => $res->sha] );
		if ( is_wp_error( $res ) ) {
			$this->app->write_log($res);
			return $res;
		}

		// Our code expects the commit id in id, not in sha
		$res->id = $res->object->sha;

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
		$repoendpoint = $url . '/repos/' . $repository;
		$branchesendpoint = $repoendpoint . '/branches';

		$response = wp_remote_request($branchesendpoint, [
			'method'  => 'GET',
			'headers' => array(
				'Authorization' => 'Bearer '.$token,
				'X-GitHub-Api-Version' => '2022-11-28',
				'Accept' => 'application/vnd.github+json',
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
				'Authorization' => 'Bearer '.$token,
				'X-GitHub-Api-Version' => '2022-11-28',
				'Accept' => 'application/vnd.github+json',
			),
			'timeout' => 30,
		]);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ($response->private) {
			return new WP_Error('404', 'Connection to private repositories is not supported with this version of PushPull');
		}

		return $branches;
	}

	/**
	 * Get the latest commit hash of the repository.
	 *
	 * @return string|WP_Error
	 */
	public function getLatestCommitHash(): string|WP_Error {
		$res = $this->call( 'GET', $this->url() . '/repos/' . $this->repository() . '/branches/' . $this->branch() );

		if ( is_wp_error( $res ) ) {
			$this->app->write_log($res);
			return $res;
		}

        return $res->commit->sha;
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
				'Authorization' => 'Bearer ' . $this->token(),
				'Accept' => 'application/vnd.github+json',
			),
			'timeout' => 60,
			'stream' => true,
			'filename' => $tempArchive,
		);
		$response = wp_remote_request( $this->url() . '/repos/' . $this->repository() . '/zipball/' . $this->branch(), $args );

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
			$this->app->state()->saveFile($relativePath, $contents);
        }
        wp_delete_file($tempArchive);
        array_map('unlink', glob("$extractedDir/*.*"));
        $zip->close();

        return $repoFiles;
    }

	/**
	 * Get repository commits.
	 * 
	 * @return array|WP_Error
	 */
	public function getRepositoryCommits(): array|WP_Error {
		$commits = $this->call( 'GET', $this->url() . '/repos/' . $this->repository() . '/commits?sha=' . $this->branch() );

		if ( is_wp_error( $commits ) ) {
			$this->app->write_log($commits);
			return $commits;
		}

		// Our code expects the commit id in id, not in sha
		foreach ($commits as $commit) {
			$commit->id = $commit->sha;
			$commit->short_id = substr($commit->sha, 0, 7);
			$commit->author_name = $commit->commit->author->name;
			$commit->committed_date = $commit->commit->author->date;
			$commit->title = $commit->commit->message;
			$commit->message = $commit->commit->message;
		}

		return array_reverse($commits);
	}

	/**
	 * Is repo public ?
	 *
	 * @return bool|WP_Error
	 */
	protected function checkPublicRepo(): bool|WP_Error {
		$repo = $this->call( 'GET', $this->url() . '/repos/' . $this->repository(), array(), false);
		if ( is_wp_error( $repo ) ) {
			$this->app->write_log($repo);
			return $repo;
		}

		return $repo->private === false;
	}

	/**
	 * Get commit details
     * @param string $commit Commit ID.
	 *
	 * @return array|WP_Error
	 */
	public function getCommitFiles(string $commit): array|WP_Error {
		$diffs = $this->call( 'GET', $this->url() . '/repos/' . $this->repository() . '/commits/' . $commit);

		if ( is_wp_error( $diffs ) ) {
			$this->app->write_log($diffs);
			return $diffs;
		}

		return array_map(function($item) {
			return $item->filename;
		}, $diffs->files);
	}

	/**
     * Get remote repository hashes.
     *
     * @return array|WP_Error
     */
    public function getRemoteHashes(): array|WP_Error {
		// First check if the repository exists
		$repo = $this->call( 'GET', $this->url() . '/repos/' . $this->repository());
		if ( is_wp_error( $repo ) ) {
			$this->app->write_log($repo);
			return $repo;
		}
		// Then check if the branch exists
		$branch = $this->call( 'GET', $this->url() . '/repos/' . $this->repository() . '/branches/' . $this->branch());
		if ( is_wp_error( $branch ) ) {
			$this->app->write_log($branch);
			return $branch;
		}
		// If we now get an error it means the repository is empty
		$tree = $this->call( 'GET', $this->url() . '/repos/' . $this->repository() . '/git/trees/' . $this->branch() . '?recursive=1' );
		if ( is_wp_error( $tree ) ) {
			return [];
		}

		$hashes = [];
		foreach ($tree->tree as $item) {
			if ($item->type === 'blob') {
				$hashes[$item->path] = $item->sha;
			}
        }

		return $hashes;
    }
}
