<?php

namespace CreativeMoods\PushPull;

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
	 * @return stdClass|WP_Error
	 */
	protected function call( $method, $endpoint, $body = array() ) {
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
		//$this->app->write_log($response);
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
	 * @return stdClass|WP_Error
	 */
	public function getRemotePostByName(string $type, string $name): stdClass|WP_Error {
		$data = $this->call( 'GET', $this->url() . '/repos/' . $this->repository() . '/contents/' . "_".$type. "/" . $name );

		if ( is_wp_error( $data ) ) {
			$this->app->write_log($data);
			return $data;
		}

		return json_decode(base64_decode($data->content));
	}

    /**
     * List repository hierarchy.
     *
     * @param string $repoName Repository name.
     * @return array Repository details.
     */
    public function listRepository(string $repoName): array {
		// TODO Change to use a local pull from the repository
/*        $this->app->write_log("Fetching remote repo contents.");

        $archiveContent = $this->call( 'GET', $this->url() . '/repos/' . $this->repository() . '/git/trees/' . $this->branch() . '?recursive=1' );
        if ($archiveContent === false) {
            throw new \Exception("Failed to download repository archive.");
        }
        $repoFiles = [];
		$this->app->write_log($archiveContent);
        foreach ($archiveContent->tree as $file) {
            if ($file->type === 'blob') {
                $repoFiles[] = [
                    'path' => $file->path,
                    'checksum' => $file->sha
                ];
            }
        }

        return $repoFiles;*/
        $this->app->write_log("Fetching remote repo contents.");

        $archiveContent = $this->call( 'GET', $this->url() . '/repos/' . $this->repository() . '/zipball/' . $this->branch() );
        if ($archiveContent === false) {
            throw new \Exception("Failed to download repository archive.");
        }
        $tempArchive = tempnam(sys_get_temp_dir(), 'repo_archive_');
        $wpfsd = new \WP_Filesystem_Direct( false );
        // TODO check result
        $wpfsd->put_contents ( $tempArchive, $archiveContent );
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
            $hash = hash_file('sha256', $filePath);
            $repoFiles[] = [
                'path' => $relativePath,
                'checksum' => $hash
            ];
        }
        wp_delete_file($tempArchive);
        array_map('unlink', glob("$extractedDir/*.*"));
        $wpfsd->rmdir($extractedDir);
        $zip->close();

        return $repoFiles;
    }

	/**
	 * Commit a post and its dependencies.
	 *
     * @param stdClass $wrap Commit data.
	 * @return stdClass|WP_Error
	 */
    public function commit(array $wrap): stdClass|WP_Error {
		// Doesn't seem we can commit multiple files in one go, so we will have to loop
		foreach ($wrap['actions'] as $action) {
			$fileData = [
				'message' => $wrap['commit_message'],
				'content' => base64_encode($action['content']),
				'branch' => $this->branch(),
				'committer' => ['name' => $wrap['author_name'], 'email' => $wrap['author_email']],
				'sha' => $this->git_exists($action['file_path']),
			];

			$endpoint = $this->url() . '/repos/' . $this->repository() . '/contents/' . $action['file_path'];
			$response = $this->call('PUT', $endpoint, $fileData);

			if (is_wp_error($response)) {
				return $response;
			}
		}

		// TODO last only
		return $response;
	}

	/**
	 * List branches for a repository.
	 *
	 * @param string $url
	 * @param string $token
	 * @param string $repository
	 * @return array|WP_Error
	 */
	public function getBranches(string $url, string $token, string $repository): array|WP_Error {
		// TODO Need to override repo, url and token
        $branches = $this->call( 'GET', $this->url() . '/repos/' . $this->repository() . '/branches' );
		if (is_wp_error($branches)) {
			return $branches;
		}

		return $branches;
	}
}
