<?php

declare(strict_types=1);

namespace PushPull\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use PushPull\Content\WordPress\WordPressAttachmentsAdapter;

final class AttachmentSyncField
{
    public function register(): void
    {
        add_filter('attachment_fields_to_edit', [$this, 'addField'], 10, 2);
        add_filter('attachment_fields_to_save', [$this, 'saveField'], 10, 2);
    }

    /**
     * @param array<string, mixed> $formFields
     * @return array<string, mixed>
     */
    public function addField(array $formFields, \WP_Post $post): array
    {
        if ($post->post_type !== 'attachment') {
            return $formFields;
        }

        $value = (string) get_post_meta($post->ID, WordPressAttachmentsAdapter::SYNC_META_KEY, true);

        $formFields['pushpull_sync_attachment'] = [
            'label' => 'PushPull sync',
            'input' => 'html',
            'html' => sprintf(
                '<label><input type="checkbox" name="attachments[%1$d][pushpull_sync_attachment]" value="1" %2$s /> %3$s</label>',
                (int) $post->ID,
                checked($value, '1', false),
                esc_html__('Sync with PushPull', 'pushpull')
            ),
            'helps' => esc_html__('Include this attachment in the PushPull managed attachments set.', 'pushpull'),
        ];

        return $formFields;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $attachment
     * @return array<string, mixed>
     */
    public function saveField(array $post, array $attachment): array
    {
        $postId = (int) ($post['ID'] ?? 0);

        if ($postId <= 0) {
            return $post;
        }

        if (! empty($attachment['pushpull_sync_attachment'])) {
            update_post_meta($postId, WordPressAttachmentsAdapter::SYNC_META_KEY, '1');
        } else {
            delete_post_meta($postId, WordPressAttachmentsAdapter::SYNC_META_KEY);
        }

        return $post;
    }
}
