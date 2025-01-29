<?php

namespace CreativeMoods\PushPull\providers;

use CreativeMoods\PushPull\PushPull;
use WP_Error;

abstract class GitProvider {
    /**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

    public function __construct( PushPull $app ) {
        $this->app = $app;
    }

    /**
     * Returns the repository
     *
     * @return string
     */
    protected function repository() {
        return get_option($this->app::REPO_OPTION_KEY);
    }

    /**
     * Returns the URL of the repository
     *
     * @return string
     */
    protected function url() {
        return get_option($this->app::HOST_OPTION_KEY);
    }

    /**
	 * Returns the user's token
	 *
	 * @return string
	 */
	public function token() {
		return (string) get_option($this->app::TOKEN_OPTION_KEY);
	}

    /**
	 * Returns the branch of the repository
	 *
	 * @return string
	 */
	public function branch() {
		return (string) get_option($this->app::BRANCH_OPTION_KEY);
	}

	/**
	 * Is repo public ?
	 *
	 * @return bool|WP_Error
	 */
    abstract protected function checkPublicRepo(): bool|WP_Error;

    /**
	 * Returns whether the repo is public
	 *
	 * @return bool|WP_Error
	 */
	public function isPublicRepo($force = false): bool|WP_Error {
        $t = get_transient($this->app::PP_PUBLIC_REPO);
        if ($t === false || $force) {
            // Transient does not exist or is expired or doesn't have a value, we need to set it
            $public = $this->checkPublicRepo();
            if (is_wp_error($public)) {
                return $public;
            }
            // We need to set a string in the transient otherwise we can't differentiate between false and not set
            set_transient($this->app::PP_PUBLIC_REPO, $public ? "public" : "private", 60*60*24);

            return $public;
        }

        return $t === "public";
	}
}
