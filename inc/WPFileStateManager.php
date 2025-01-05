<?php

namespace CreativeMoods\PushPull;

class WPFileStateManager {
    const FILE_STATE_TRANSIENT          = 'pushpull_local_clone';
    const COMMIT_LOG_TRANSIENT          = 'pushpull_repo_commit_log';
    const LATEST_COMMIT_HASH_TRANSIENT  = 'pushpull_latest_commit_hash';
    const EXPIRATION = 0; // Transients do not expire (set 0 for persistent).

	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * Instantiates a new Rest object.
	 *
	 * @param PushPull $app Application container.
	 */
	public function __construct( PushPull $app ) {
		$this->app = $app;
	}

    /**
     * Retrieve the current commit log.
     * @return array
     */
    public function getCommitLog() {
        return get_transient(self::COMMIT_LOG_TRANSIENT) ?: [];
    }

    /**
     * Retrieve the latest commit hash.
     * @return string|null
     */
    public function getLatestCommitHash(): string|null {
        return get_transient(self::LATEST_COMMIT_HASH_TRANSIENT) ?: null;
    }

    /**
     * Create a commit
     *
     * @return void
     */
    public function createCommit( string $message, array $changes ) {
        // Append commit to commit log
        $commitlog = $this->getCommitLog();
        $user = get_userdata(get_current_user_id());
        $commitlog[] = [
            'id' => $this->generateCommitId(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'author' => $user->display_name,
            'message' => $message,
            'changes' => $changes,
        ];

        // Persist commit log
        $this->saveCommitLog($commitlog);
        $this->saveLatestCommitHash($commitlog[count($commitlog) - 1]['id']);
        $this->app->write_log("Persisted ". json_encode($commitlog[count($commitlog) - 1]));
    }

    /**
     * Import commits
     *
     * @return void
     */
    public function importCommits( array $commits, bool $overwrite = false ) {
        if ($overwrite) {
            $commitlog = [];
        } else {
            $commitlog = $this->getCommitLog();
        }
        foreach ($commits as $commit) {
            $commitlog[] = [
                'id' => $commit->id,
                'timestamp' => $commit->committed_date,
                'author' => $commit->author_name,
                'message' => $commit->message,
                'changes' => [], // TODO Do we need this ?
            ];
        }

        // Persist commit log
        $this->app->write_log("Persisting ". json_encode($commitlog));
        $this->saveCommitLog($commitlog);
    }

    /**
     * Generate unique commit ID.
     *
     * @return string
     */
    public function generateCommitId() {
        return sha1(uniqid('commit_', true));
    }

    /**
     * Retrieve the current state from transients.
     * @return array
     */
    private function getState() {
        return get_transient(self::FILE_STATE_TRANSIENT) ?: [];
    }

    /**
     * Save the updated state to transients.
     * @param array $state
     * @return bool
     */
    public function saveState($state) {
        return set_transient(self::FILE_STATE_TRANSIENT, $state, self::EXPIRATION);
    }

    /**
     * Save the commit log to its transient.
     * @param array $commitlog
     * @return bool
     */
    private function saveCommitLog($commitlog) {
        return set_transient(self::COMMIT_LOG_TRANSIENT, $commitlog, self::EXPIRATION);
    }

    /**
     * Save the latest commit hash to its transient.
     * @param array $hash
     * @return bool
     */
    public function saveLatestCommitHash($hash) {
        $this->app->write_log("Saving latest commit hash: $hash");
        return set_transient(self::LATEST_COMMIT_HASH_TRANSIENT, $hash, self::EXPIRATION);
    }

    /**
     * Add or update a file in the local state.
     * 
     * @param string $filePath The relative file path.
     * @param string $content The file content encoded in JSON or base64.
     * 
     * @return string The hash of the file content.
     */
    public function saveFile($filePath, $content): string {
        $state = self::getState();
        $hash = md5($content);

        $state[$filePath] = [
            /* TODO added for compat */
            'path' => $filePath,
            'checksum' => $hash,
            /* end added for compat */
            'hash' => $hash,
            'updated_at' => time(),
            'status' => 'active',
        ];

        // Save state
        $state = self::saveState($state);
        // Save content to a separate transient
        set_transient(self::getFileContentTransientKey($filePath), $content, self::EXPIRATION);

        return $hash;
    }

    /**
     * Get file content by path.
     * @param string $filePath The relative file path.
     * @return string|null
     */
    public function getFile($filePath) {
        $this->app->write_log("Getting file: $filePath");
        $state = self::getState();
        if (!isset($state[$filePath])) {
            return null;
        }
        return get_transient(self::getFileContentTransientKey($filePath));
    }

    /**
     * Delete a file from the local state and create delete commit.
     * @param string $filePath The relative file path.
     * 
     * @return bool
     */
    public function deleteFile($filePath) {
        $state = self::getState();

        if (!isset($state[$filePath])) {
            return false;
        }

        // Remove the file from the state
        unset($state[$filePath]);
        // Remove the file content transient
        delete_transient(self::getFileContentTransientKey($filePath));
        // Create delete commit
        $this->createCommit("Deleted file: $filePath", [$filePath => null]);

        // Save state
        return self::saveState($state);
    }

    /**
     * Get all file metadata.
     * @return array
     */
    public function listFiles() {
        return self::getState();
    }

    /**
     * Generate a transient key for file content.
     * @param string $filePath
     * @return string
     */
    private function getFileContentTransientKey($filePath) {
        return 'wp_file_content_' . md5($filePath);
    }

    /**
     * Update the commit ID of a commit in the commit log, usually after a push.
     * @param string $oldId The old commit ID.
     * @param string $newId The new commit ID.
     * 
     * @return bool
     */
    public function updateCommitId($oldId, $newId) {
        $commitlog = $this->getCommitLog();

        foreach ($commitlog as $key => $commit) {
            if ($commit['id'] === $oldId) {
                $commitlog[$key]['id'] = $newId;
                // If this is the last commit, update the latest commit hash
                if ($key === array_key_last($commitlog)) {
                    $this->saveLatestCommitHash($newId);
                }
                break;
            }
        }

        return $this->saveCommitLog($commitlog);
    }
}
