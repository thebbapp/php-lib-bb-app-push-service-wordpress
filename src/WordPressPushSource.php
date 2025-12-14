<?php

declare(strict_types=1);

namespace BbApp\PushService\WordPress;

use BbApp\PushService\WordPressBase\WordPressBasePushSource;
use UnexpectedValueException;
use WP_Comment;
use WP_Post;

/**
 * WordPress-specific implementation of push notification source for posts and comments.
 */
class WordPressPushSource extends WordPressBasePushSource
{
	/**
	 * Extracts message data from WordPress post or comment for push notifications.
	 */
	public function extract_message_data($object): array
	{
		$section__title = null;

		if ($object instanceof WP_Post) {
			if ($object->post_author > 0) {
				$user = get_user_by('id', $object->post_author);

				if ($user) {
					$username = $user->display_name;
				}
			}

			if (!isset($username)) {
				$username = __('Anonymous', 'bb-app');
			}

			return compact('username', 'section__title') + [
				'id' => $object->ID,
				'user_id' => (int) $object->post_author,
				'title' => $object->post_title,
				'content' => $this->get_message_content($object->post_content)
			];
		} else if ($object instanceof WP_Comment) {
			if ($object->user_id > 0) {
				$user = get_user_by('id', $object->user_id);

				if ($user) {
					$username = $user->display_name;
				}
			} else if (!empty($object->comment_author)) {
				$username = $object->comment_author;
			}

			if (!isset($username)) {
				$username = 'Anonymous';
			}

			return compact('username', 'section__title') + [
				'id' => $object->comment_ID,
				'user_id' => (int) $object->user_id,
				'title' => null,
				'content' => $this->get_message_content($object->comment_content),
				'post__title' => get_the_title($object->comment_post_ID)
			];
		} else {
			throw new UnexpectedValueException();
		}
	}

	/**
	 * Builds subscription targets for post categories and comment threads.
	 */
	public function build_push_service_targets_for_object($object): array
	{
		$targets = [];

		if ($object instanceof WP_Post) {
            if ($object->post_type === $this->content_source->get_entity_types('post')) {
                $term_ids = wp_get_object_terms(
                    $object->ID,
                    $this->content_source->get_entity_types('section'),
                    ['fields' => 'ids']
                );

                if (!is_wp_error($term_ids)) {
                    foreach ($term_ids as $term_id) {
                        $targets[] = [$this->content_source->get_entity_types('section'), (int) $term_id];
                    }
                }
            }
		} elseif ($object instanceof WP_Comment) {
			$comment_parent = (int) $object->comment_parent;

			if ($comment_parent === 0) {
				$targets[] = [$this->content_source->get_entity_types('post'), (int) $object->comment_post_ID];
			} else {
				$targets[] = [$this->content_source->get_entity_types('comment'), $comment_parent];
			}
		}

		return $targets;
	}

	/**
	 * Handles WordPress post creation for push notifications.
	 */
    public function wp_insert_post($post_ID, $post, bool $updating): void
    {
        if (
            $updating ||
            ($post->post_type !== $this->content_source->get_entity_types('post')) ||
            wp_is_post_autosave($post) ||
            wp_is_post_revision($post) ||
            (function_exists('wp_doing_rest') && \wp_doing_rest())
        ) {
            return;
        }

        $this->handle_content_insertion($post);
    }

	/**
	 * Handles WordPress comment creation for push notifications.
	 */
    public function wp_insert_comment($comment_ID, $commentdata): void
    {
        $comment = get_comment($comment_ID);

        if (
            empty($comment) ||
            ($comment->comment_approved !== '1') ||
            (function_exists('wp_doing_rest') && \wp_doing_rest())
        ) {
            return;
        }

        $this->handle_content_insertion($comment);
    }

	/**
	 * Registers WordPress hooks for post and comment insertion.
	 */
	public function register(): void
	{
        parent::register();

        add_action('wp_insert_post', [$this, 'wp_insert_post'], 10, 3);
        add_action('wp_insert_comment', [$this, 'wp_insert_comment'], 10, 3);
	}

	/**
	 * Gets the object type from WordPress post or comment.
	 */
	protected function get_object_type($content): string
	{
		if ($content instanceof WP_Post) {
			return $this->content_source->get_entity_types('post');
		}

		if ($content instanceof WP_Comment) {
			return $this->content_source->get_entity_types('comment');
		}

		return '';
	}
}
