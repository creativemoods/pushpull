<?php

namespace CreativeMoods\PushPull\providers;

use WP_Error;
use stdClass;

class BitbucketProvider extends GitProvider implements GitProviderInterface {
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
	 * Bitbucket API interface and response handler
	 *
	 * @param string $method HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param array  $body Request body.
	 *
	 * @return array|WP_Error
	 */
	protected function call( $method, $endpoint, $body = array() ) {
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Basic '.$this->token(),
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
	 * Issues a HEAD request to find out.
	 * We need this because we need to specify update or create in a commit.
	 *
	 * @param string $name The name of the file
	 *
	 * @return bool
	 */
	protected function git_exists( $name ) {
		$res = $this->head($this->url() . '/projects/' . $this->repository() . '/repository/files/' . $name. "?ref=" . $this->branch());
		return array_key_exists('response', $res) && array_key_exists('code', $res['response']) && $res['response']['code'] === 200;
	}

	/**
	 * Get a post by type and name.
	 *
	 * @return stdClass|WP_Error
	 */
	public function getRemotePostByName(string $type, string $name): stdClass|WP_Error {
		$data = $this->call( 'GET', $this->url() . '/projects/' . $this->repository() . '/repository/files/' . "_".$type."%2F" . $name . '/raw' );

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
		// TODO Implement
		$res = $this->call( 'DELETE', $this->url() . '/projects/' . urlencode($this->repository()) . '/repository/files/' . "_".$type."%2F" . $name, ['branch' => $this->branch(), 'commit_message' => 'Deleted by PushPull'] );
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
		foreach ($wrap['actions'] as $action) {
			$action['action'] = $this->git_exists($action['file_path']) ? 'update' : 'create';
		}
		$wrap['branch'] = $this->branch();
		// TODO Check if $wrap was really updated
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
	public function getBranches(string $url, string $token, string $repository): array|WP_Error {
		// TODO Need to override repo, url and token
        $branches = $this->call( 'GET', $this->url() . '/repositories/' . $this->repository() . '/refs/branches' );
		if (is_wp_error($branches)) {
			return $branches;
		}

		return array_map(function($branch) {
			return ['name' => $branch->name];
		}, $branches->values);
	}
}
