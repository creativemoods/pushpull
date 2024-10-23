<?php

namespace CreativeMoods\PushPull;

class GitProvider {
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
        return get_option($this->app::URL_OPTION_KEY);
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
}
