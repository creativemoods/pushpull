<?php
/**
 * GitProviderInterface for Git operations.
 * @package PushPull
 */

namespace CreativeMoods\PushPull\providers;

use WP_Error;
use stdClass;

/**
 * Interface GitProviderInterface
 */
interface GitProviderInterface {
    /**
     * List repository hierarchy.
     *
     * @param string $repoName Repository name.
     * @return array Repository details.
     */
    public function listRepository(string $repoName): array;

	/**
	 * Get a post by type and name.
	 *
     * @param string $type Post type.
     * @param string $name Post name.
	 * @return string|stdClass|WP_Error
	 */
    public function getRemotePostByName(string $type, string $name): string|stdClass|WP_Error;

	/**
	 * Get posts by type.
	 *
     * @param string $type Post type.
	 * @return array
	 */
    public function getRemotePostsByType(string $type): array;

	/**
	 * Delete a post by type and name.
	 *
     * @param string $type Post type.
     * @param string $name Post name.
	 * @return bool|WP_Error
	 */
    public function deleteRemotePostByName(string $type, string $name): bool|WP_Error;

	/**
	 * Commit a post and its dependencies.
	 *
     * @param stdClass $wrap Commit data.
	 * @return stdClass|WP_Error
	 */
    public function commit(array $wrap): stdClass|WP_Error;

	/**
	 * Get a list of branches for a url, token and repository.
	 *
     * @param string $url.
     * @param string $token.
     * @param string $repository.
     * 
	 * @return array|WP_Error
	 */
    public function getBranches(string $url, string $token, string $repository): array|WP_Error;

	/**
	 * Commit deploy script.
	 *
	 * @param string $deployscript
	 * @return bool|WP_Error
	 */
	public function setDeployScript(string $deployscript): bool|WP_Error;
}