<?php
/**
 * WP Better Emails for BuddyPress Core
 *
 * @package WPBE_BP
 * @subpackage Core
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * Core class for WP Better Emails for BuddyPress
 *
 * @package WPBE_BP
 * @subpackage Classes
 */
class WPBE_BP {

	/**
	 * Creates an instance of the WPBE_BP class
	 *
	 * @return WPBE_BP object
	 * @static
	 */
	public static function init() {
		static $instance = false;

		if ( !$instance ) {
			$instance = new WPBE_BP;
		}

		return $instance;
	}

	/**
	 * Constructor.
	 */
	function __construct() {
		// if WP Better Emails does not exist, throw a notice with activation or installation link for WPBE
		if ( ! class_exists( 'WP_Better_Emails' ) ) {
			add_action( 'admin_notices',                          array( $this, 'get_wpbe' ) );
			return;
		}

		// add notification settings to BP
		add_action( 'bp_members_notification_settings_before_submit', array( $this, 'screen' ) );

		// filter WP email to get the recipient
		add_filter( 'wp_mail',                                        array( $this, 'wp_mail_filter' ) );

		// hijack WP email content type from WPBE
		add_filter( 'wp_mail_content_type',                           array( $this, 'set_wp_mail_content_type' ), 999 );

		// if we're using an older version of WP Better Emails, we can stop the rest
		// of this plugin!
		if ( ! $this->is_newer_version() )
			return;

		/**
		 * Data stashing
		 *
		 * In many places, we need the original data associated with
		 * the notification (the activity item, the friendship, etc) in
		 * order to construct the text of the email notification. Until
		 * BuddyPress is fixed to pass this content in an intelligent
		 * way, we use some hacks to hook into the save process so that
		 * we can (a) fetch and stash the necessary data before the
		 * notification is constructed, and (b) remove the stashed data
		 * when we're done
		 */

		// Activity - normal activity items
		add_action( 'bp_activity_after_save', array( $this, 'save_activity_content' ),    9 );
		add_action( 'bp_activity_after_save', array( $this, 'remove_activity_content' ),  999 );

		// Activity - comment notifications are sent at bp_activity_comment_posted
		add_action( 'bp_activity_comment_posted', array( $this, 'save_activity_comment' ),    9 );
		add_action( 'bp_activity_comment_posted', array( $this, 'remove_activity_comment' ),  999 );

		// Friends - requests
		add_action( 'friends_friendship_requested', array( $this, 'save_friendship' ), 9 );
		add_action( 'friends_friendship_requested', array( $this, 'remove_friendship' ), 999 );

		// Friends - requests
		add_action( 'friends_friendship_accepted', array( $this, 'save_friendship' ), 9 );
		add_action( 'friends_friendship_accepted', array( $this, 'remove_friendship' ), 999 );

		// Groups - membership request accepted
		add_action( 'groups_membership_accepted', array( $this, 'save_membership' ), 9, 3 );
		add_action( 'groups_membership_accepted', array( $this, 'remove_membership' ), 999, 3 );

		// Groups - membership request rejected
		add_action( 'groups_membership_rejected', array( $this, 'save_membership' ), 9, 3 );
		add_action( 'groups_membership_rejected', array( $this, 'remove_membership' ), 999, 3 );

		/** Email content filtering **********************************/

		// Activity - at-mentions
		add_filter( 'bp_activity_at_message_notification_message',    array( $this, 'use_html_for_at_message' ),       99, 5 );

		// Activity - comments
		add_filter( 'bp_activity_new_comment_notification_message',   array( $this, 'use_html_for_activity_replies' ), 99, 5 );

		// Friends - requests
		add_filter( 'friends_notification_new_request_message', array( $this, 'use_html_for_friend_request' ), 99, 5 );

		// Friends - accepted
		add_filter( 'friends_notification_accepted_request_message', array( $this, 'use_html_for_friend_accept' ), 99, 4 );

		// Groups - group updated
		add_filter( 'groups_notification_group_updated_message', array( $this, 'use_html_for_group_updated' ), 99, 4 );

		// Groups - membership request
		add_filter( 'groups_notification_new_membership_request_message', array( $this, 'use_html_for_membership_request' ), 99, 6 );

		// Groups - membership completed
		add_filter( 'groups_notification_membership_request_completed_message', array( $this, 'use_html_for_membership_request_completed' ), 99, 6 );

		// Groups - member promoted
		add_filter( 'groups_notification_promoted_member_message', array( $this, 'use_html_for_member_promoted' ), 99, 5 );

		// Groups - member invitation
		add_filter( 'groups_notification_group_invites_message', array( $this, 'use_html_for_group_invite' ), 99, 7 );

		/** BPGES ****************************************************/

		// Activity - bbPress 1.x forum posts/replies (BPGES)
		add_filter( 'bp_ass_new_topic_content',             array( $this, 'use_html_for_legacy_forum_topic' ),   99, 4 );
		add_filter( 'bp_ass_forum_reply_content',           array( $this, 'use_html_for_legacy_forum_reply' ),   99, 4 );
		add_filter( 'bp_ass_forum_notification_message',    array( $this, 'adjust_legacy_forum_post_footer' ),   10, 2 );

		// Activity - Group activity
		add_filter( 'bp_ass_activity_notification_action',  array( $this, 'use_html_for_ges_activity_action' ),  99, 2 );
		add_filter( 'bp_ass_activity_notification_subject', array( $this, 'clean_ges_activity_subject' ),        99 );
		add_filter( 'bp_ass_activity_notification_content', array( $this, 'use_html_for_ges_activity_content' ), 99, 4 );
		add_filter( 'bp_ass_activity_notification_message', array( $this, 'adjust_ges_activity_email_footer' ), 10, 2 );

		/** Plain-text conversion ************************************/

		// fix password reset link
		add_filter( 'wpbe_plaintext_body',    array( $this, 'password_reset_fix' ), 0 );

		// convert HTML to plaintext body
		add_filter( 'wpbe_plaintext_body',    array( $this, 'convert_html_to_plaintext' ) );

		// Filters we run to convert HTML to plain-text
		add_filter( 'wpbe_html_to_plaintext', 'stripslashes',               5 );
		add_filter( 'wpbe_html_to_plaintext', 'wp_kses_normalize_entities', 5 );
		add_filter( 'wpbe_html_to_plaintext', 'wpautop' );
		add_filter( 'wpbe_html_to_plaintext', 'wp_specialchars_decode',     99 );
	}

	/**
	 * Custom textdomain loader. Not used at the moment.
	 *
	 * Checks WP_LANG_DIR for the .mo file first, then the plugin's language folder.
	 * Allows for a custom language file other than those packaged with the plugin.
	 *
	 * @uses load_textdomain() Loads a .mo file into WP
	 */
	function localization() {
		$mofile		= sprintf( 'wpbe-bp-%s.mo', get_locale() );
		$mofile_global	= WP_LANG_DIR . '/' . $mofile;
		$mofile_local	= dirname( __FILE__ ) . '/languages/' . $mofile;

		if ( is_readable( $mofile_global ) )
			return load_textdomain( 'wpbe-bp', $mofile_global );
		elseif ( is_readable( $mofile_local ) )
			return load_textdomain( 'wpbe-bp', $mofile_local );
		else
			return false;
	}

	/**
	 * Get the recipient from wp_mail's 'To' email address
	 *
	 * @global object $bp
	 * @param array $args Arguments provided via {@link wp_mail()} filter.
	 * @return array
	 */
	function wp_mail_filter( $args ) {
		global $bp;

		if ( ! empty( $args['to'] ) ) {
			$this->user_id = email_exists( $args['to'] );
		}

		return $args;
	}

	/**
	 * See if the user in question wants HTML or plain-text email.
	 */
	function set_wp_mail_content_type( $content_type ) {
		$is_email_html = bp_get_user_meta( $this->user_id, 'notification_html_email', true );

		// if user wants plain text, let's set the email to send in plain text and
		// disable WP Better Emails' custom PHPMailer sendout and add our own hook
		if ( $is_email_html == 'no' ) {
			global $wp_better_emails;

			// remove WP Better Emails' default HTML hook
			remove_action( 'phpmailer_init', array( $wp_better_emails, 'send_html' ) );

			// add our own custom plain-text hook only if we're using a newer version
			// of WP Better Emails
			if ( $this->is_newer_version() ) {
				add_action( 'phpmailer_init', array( $this, 'send_plaintext_only' ) );
			}

			// make sure we return the content type as plain-text
			return 'text/plain';
		}

		return $content_type;
	}

	/** Activity component ***********************************************/

	/**
	 * Temporarily save the full activity content.
	 *
	 * The reason why this is done is currently, activity notification emails
	 * strip all HTML before sending.
	 *
	 * However, we don't want HTML stripped from emails.  So we have to resort to
	 * this method until BP fixes this in core.
	 *
	 * @param obj $activity The BP activity object
	 */
	public function save_activity_content( $activity ) {
		global $bp;

		$bp->activity->temp = $activity;
	}

	/**
	 * Remove our locally-cached activity object.
	 *
	 * Clear up any remnants from our hack.
	 *
	 * @see WPBE_BP::save_activity_content()
	 *
	 * @param obj $activity The BP activity object
	 */
	public function remove_activity_content( $activity ) {
		global $bp;

		// remove temporary saved activity content
		if ( ! empty( $bp->activity->temp ) ) {
			unset( $bp->activity->temp );
		}

	}

	/**
	 * Temporarily save the full activity content after activity comments.
	 *
	 * Wrapper for self::save_activity_content().
	 *
	 * @param obj $comment_id The ID of the activity comment.
	 */
	public function save_activity_comment( $comment_id ) {
		$comment = new BP_Activity_Activity( $comment_id );
		$this->save_activity_content( $comment );
	}

	/**
	 * Remove temporarily stashed activity content after activity comments.
	 *
	 * Wrapper for self::remove_activity_content().
	 *
	 * @param obj $comment_id The ID of the activity comment.
	 */
	public function remove_activity_comment( $comment_id ) {
		$comment = new BP_Activity_Activity( $comment_id );
		$this->remove_activity_content( $comment );
	}

	/**
	 * Modify the @mention email to use HTML instead of plain-text.
	 *
	 * Uses our locally-cached activity content, which contains HTML from
	 * {@link WPBE_BP::save_activity_content()}.
	 *
	 * @param str $retval The original email message
	 * @param stt $poster_name The person mentioning the email recipient
	 * @param str $content The original email content
	 * @param str $message_link The activity permalink
	 * @param str $settings_link The settings link for the email recipient
	 *
	 * @return str The modified email content containing HTML if available.
	 */
	function use_html_for_at_message( $retval, $poster_name, $content, $message_link, $settings_link ) {
		global $bp;

		// sanity check!
		if ( empty( $bp->activity->temp->content ) ) {
			return $retval;

		// grab our activity content from our locally-cached variable
		} else {
			$activity = $bp->activity->temp;
		}

		$poster_link = bp_core_get_userlink( $activity->user_id );
		$content = stripslashes( $activity->content );
		$reply_link = sprintf( '<a href="%s">%s</a>', $message_link, __( 'View/Reply', 'buddypress' ) );

		if ( bp_is_active( 'groups' ) && bp_is_group() ) {
			$group = groups_get_current_group();
			$group_link = '<a href="' . bp_get_group_permalink( $group ) . '">' . $group->name . '</a>';
			$message = sprintf( __(
'%1$s mentioned you in the group %2$s:

<blockquote>%3$s</blockquote>

%4$s ', 'buddypress' ), $poster_link, $group_link, $content, $reply_link );
		} else {
			$message = sprintf( __(
'%1$s mentioned you in an update:

<blockquote>%2$s</blockquote>

%3$s', 'buddypress' ), $poster_link, $content, $reply_link );
		}

		$message .= sprintf( ' &middot; <a href="%s">%s</a>', $settings_link, __( 'Notification Settings', 'buddypress' ) );

		return $message;
	}

	/**
	 * Modify the activity reply email to use HTML instead of plain-text.
	 *
	 * Uses our locally-cached activity content, which contains HTML in
	 * {@link WPBE_BP::save_activity_content()}.
	 *
	 * @param str $retval The original email message
	 * @param stt $poster_name The person mentioning the email recipient
	 * @param str $content The original email content
	 * @param str $message_link The activity permalink
	 * @param str $settings_link The settings link for the email recipient
	 *
	 * @return str The modified email content containing HTML if available.
	 */
	function use_html_for_activity_replies( $retval, $poster_name, $content, $thread_link, $settings_link ) {
		global $bp;

		// sanity check!
		if ( empty( $bp->activity->temp->content ) ) {
			return $retval;

		// grab our activity content from our locally-cached variable
		} else {
			$activity = $bp->activity->temp;
		}

		// the following is basically a copy of bp_activity_new_comment_notification()
		$sender_link = bp_core_get_userlink( $activity->user_id );
		$reply_link = sprintf( '<a href="%s">%s</a>', $thread_link, __( 'View/Reply', 'buddypress' ) );

		$message = sprintf( __( '%1$s replied to one of your updates:

<blockquote>%2$s</blockquote>

%3$s', 'buddypress' ), $sender_link, $content, $reply_link );

		$message .= sprintf( ' &middot; <a href="%s">%s</a>', $settings_link, __( 'Notification Settings', 'buddypress' ) );

		return $message;
	}

	/** Friends component ************************************************/

	/**
	 * Stash friendship data for later use.
	 *
	 * @param int $friendship_id
	 */
	public function save_friendship( $friendship_id ) {
		$friendship = new BP_Friends_Friendship( $friendship_id );
		buddypress()->friends->temp = $friendship;
	}

	/**
	 * Remove stashed friendship data.
	 *
	 * @param int $friendship_id
	 */
	public function remove_friendship( $friendship_id ) {
		unset( buddypress()->friends->temp );
	}

	/**
	 * Build HTML content for friend request emails.
	 *
	 * @param string $retval Originally formatted message.
	 * @param string $initiator_name Name of the friendship originator.
	 * @param string $initiator_link URL of the initiator's profile.
	 * @param string $all_requests_link URL of the user's requests page.
	 * @param string $settings_link URL of the user's notification settings page.
	 * @return string
	 */
	function use_html_for_friend_request( $retval, $initiator_name, $initiator_link, $all_requests_link, $settings_link ) {
		// sanity check!
		if ( empty( buddypress()->friends->temp ) ) {
			return $retval;

		// grab our friendship content from our locally-cached variable
		} else {
			$friendship = buddypress()->friends->temp;
		}

		$initiator_link = bp_core_get_userlink( $friendship->initiator_user_id );

		$content = sprintf( __( '
%1$s wants to add you as a friend.

%2$s &middot; %3$s', 'buddypress' ),
			$initiator_link,
			sprintf( '<a href="%s">%s</a>', $all_requests_link, __( 'View/Reply', 'buddypress' ) ),
			sprintf( '<a href="%s">%s</a>', $settings_link, __( 'Notifications Settings', 'buddypress' ) )
		);

		return $content;
	}

	/**
	 * Build HTML content for friend acceptance emails.
	 *
	 * @param string $retval Originally formatted message.
	 * @param string $friend_name Name of the accepting friend.
	 * @param string $friend_link URL of the accepting friend's profile.
	 * @param string $settings_link URL of the user's notification settings page.
	 * @return string
	 */
	function use_html_for_friend_accept( $retval, $friend_name, $friend_link, $settings_link ) {
		// sanity check!
		if ( empty( buddypress()->friends->temp ) ) {
			return $retval;

		// grab our friendship content from our locally-cached variable
		} else {
			$friendship = buddypress()->friends->temp;
		}

		$friend_link = bp_core_get_userlink( $friendship->friend_user_id );

		$content = sprintf( __( '
%1$s accepted your friend request.

%2$s', 'buddypress' ),
			$friend_link,
			sprintf( '<a href="%s">%s</a>', $settings_link, __( 'Notifications Settings', 'buddypress' ) )
		);

		return $content;
	}

	/** Groups component *************************************************/

	/**
	 * Stash the accepted/rejected status of a processed membership request.
	 *
	 * @param int $requesting_user_id
	 * @param int $group_id
	 * @param bool $accepted
	 */
	public function save_membership( $requesting_user_id, $group_id, $accepted ) {
		buddypress()->groups->temp = $accepted;
	}

	/**
	 * Removed the stashed accepted/rejected status of a processed membership request.
	 *
	 * @param int $requesting_user_id
	 * @param int $group_id
	 * @param bool $accepted
	 */
	public function remove_membership( $requesting_user_id, $group_id, $accepted ) {
		unset( buddypress()->groups->temp );
	}

	/**
	 * Build HTML content for group updated emails.
	 *
	 * @param string $retval Originally formatted message.
	 * @param object $group Group object.
	 * @param string $group_link URL of the group.
	 * @param string $settings_link URL of the user's notification settings page.
	 * @return string
	 */
	function use_html_for_group_updated( $retval, $group, $group_link, $settings_link ) {
		$group_url = bp_get_group_permalink( $group );
		$group_link = '<a href="' . $group_url . '">' . $group->name . '</a>';

		$content = sprintf( __(
'Group details for the group %1$s were updated.

%2$s &middot; %3$s', 'buddypress' ),
			$group_link,
			sprintf( '<a href="%s">%s</a>', $group_url, __( 'View', 'buddypress' ) ),
			sprintf( '<a href="%s">%s</a>', $settings_link, __( 'Notifications Settings', 'buddypress' ) )
		);

		return $content;
	}

	/**
	 * Build HTML content for group membership request emails.
	 *
	 * @param string $retval Originally formatted message.
	 * @param object $group Group object.
	 * @param string $requesting_user_name Name of the user requesting
	 *        membership.
	 * @param string $profile_link URL of the requesting user's profile.
	 * @param string $group_requests URL of the membership requests admin
	 *        page.
	 * @param string $settings_link URL of the notification settings page
	 *        for the current user.
	 * @return string
	 */
	function use_html_for_membership_request( $retval, $group, $requesting_user_name, $profile_link, $group_requests, $settings_link ) {
		$group_url = bp_get_group_permalink( $group );
		$group_link = '<a href="' . $group_url . '">' . $group->name . '</a>';
		$user_link = sprintf( '<a href="%s">%s</a>', $profile_link, $requesting_user_name );

		$content = sprintf( __(
'%1$s wants to join the group %2$s.

Because you are the administrator of this group, you must either accept or reject the membership request.

%3$s &middot; %4$s', 'buddypress' ),
			$user_link,
			$group_link,
			sprintf( '<a href="%s">%s</a>', $group_requests, __( 'View pending memberships for this group', 'buddypress' ) ),
			sprintf( '<a href="%s">%s</a>', $settings_link, __( 'Notifications Settings', 'buddypress' ) )
		);

		return $content;
	}

	/**
	 * Build HTML content for group membership request completed emails.
	 *
	 * @param string $retval Originally formatted message.
	 * @param object $group Group object.
	 * @param string $group_url URL of the group.
	 * @param string $settings_link URL of the notification settings page
	 *        for the current user.
	 * @return string
	 */
	function use_html_for_membership_request_completed( $retval, $group, $group_url, $settings_link ) {
		// sanity check!
		if ( ! isset( buddypress()->groups->temp ) ) {
			return $retval;

		// grab our friendship content from our locally-cached variable
		} else {
			$accepted = buddypress()->groups->temp;
		}

		$group_link = '<a href="' . $group_url . '">' . $group->name . '</a>';

		if ( $accepted ) {
			$content = sprintf( __(
'Your membership request for the group %1$s has been accepted.

%2$s &middot; %3$s', 'buddypress' ),
				$group_link,
				sprintf( '<a href="%s">%s</a>', $group_url, __( 'Visit', 'buddypress' ) ),
				sprintf( '<a href="%s">%s</a>', $settings_link, __( 'Notifications Settings', 'buddypress' ) )
			);
		} else {
			$content = sprintf( __(
'Your membership request for the group %1$s has been rejected.

To submit another request, visit the group: %2$s

%3$s', 'buddypress' ),
				$group_link,
				$group_link,
				sprintf( '<a href="%s">%s</a>', $settings_link, __( 'Notifications Settings', 'buddypress' ) )
			);
		}

		return $content;
	}

	/**
	 * Build HTML content for member promoted emails.
	 *
	 * @param string $retval Originally formatted message.
	 * @param object $group Group object.
	 * @param string $promoted_to New group status.
	 * @param string $group_url URL of the group.
	 * @param string $settings_link URL of the notification settings page
	 *        for the current user.
	 * @return string
	 */
	function use_html_for_member_promoted( $retval, $group, $promoted_to, $group_url, $settings_link ) {
		$group_link = '<a href="' . $group_url . '">' . $group->name . '</a>';

		$content = sprintf( __(
'You have been promoted to %1$s for the group %2$s.

%2$s &middot; %3$s', 'buddypress' ),
			$promoted_to,
			$group_link,
			sprintf( '<a href="%s">%s</a>', $group_url, __( 'Visit', 'buddypress' ) ),
			sprintf( '<a href="%s">%s</a>', $settings_link, __( 'Notifications Settings', 'buddypress' ) )
		);

		return $content;
	}

	/**
	 * Build HTML content for group invitation emails.
	 *
	 * @todo Invite Anyone
	 *
	 * @param string $retval Originally formatted message.
	 * @param object $group Group object.
	 * @param string $inviter_name Name of the inviting member.
	 * @param string $inviter_url URL of the inviting member's profile.
	 * @param string $invites_url URL where user can manage invitations.
	 * @param string $group_url URL of the group.
	 * @param string $settings_link URL of the notification settings page
	 *        for the current user.
	 * @return string
	 */
	function use_html_for_group_invite( $retval, $group, $inviter_name, $inviter_url, $invites_url, $group_url, $settings_link ) {
		$group_link = '<a href="' . $group_url . '">' . $group->name . '</a>';

		$content = sprintf( __(
'%1$s has invited you to the group %2$s.

%3$s &middot; %4$s &middot; %5$s', 'buddypress' ),
			sprintf( '<a href="%s">%s</a>', $inviter_url, $inviter_name ),
			$group_link,
			sprintf( '<a href="%s">%s</a>', $invites_url, __( 'Accept/Reject', 'buddypress' ) ),
			sprintf( '<a href="%s">%s</a>', $group_url, __( 'Visit Group', 'buddypress' ) ),
			sprintf( '<a href="%s">%s</a>', $settings_link, __( 'Notifications Settings', 'buddypress' ) )
		);

		return $content;
	}

	/** BuddyPress Group Email Subscription ******************************/

	/**
	 * Modify the new forum topic notification email to use HTML.
	 *
	 * Works only for bbPress 1.x legacy forums installed in groups.
	 * Requires BuddyPress Group Email Subscription.
	 *
	 * @param string $retval The original email message.
	 * @param object $activity Data about the activity item. Note: This
	 *        is not a real BP activity item, but one faked by BPGES.
	 * @param object $post The bbPress 1.x topic generated from {@link get_topic()}.
	 * @param object $group The BP_Groups_Group object.
	 * @return string The modified email content containing HTML if available.
	 */
	function use_html_for_legacy_forum_topic( $retval, $activity, $topic, $group ) {
		$group_url = bp_get_group_permalink( $group );
		$topic_url = $activity->primary_link;

		// if group is not public, use login URL to verify authentication and for
		// easier redirection after logging in
		if ( $group->status != 'public' ) {
			$topic_url = ass_get_login_redirect_url( $activity->primary_link );
		}

		$content = sprintf( __(
'%1$s started the forum topic %2$s in the group %3$s:

<blockquote>%4$s</blockquote>

%5$s', 'buddypress' ),
			bp_core_get_userlink( $activity->user_id ),
			sprintf( '<a href="%s">%s</a>', $topic_url, $topic->title ),
			sprintf( '<a href="%s">%s</a>', $group_url, $group->name ),
			$activity->content,
			sprintf( '<a href="%s">%s</a>', $topic_url, __( 'View/Reply', 'buddypress' ) )
		);

		// Don't let GES strip goodies
		remove_filter( 'ass_clean_content', 'strip_tags', 4 );
		remove_filter( 'ass_clean_content', 'ass_convert_links', 6 );
		remove_filter( 'ass_clean_content', 'ass_html_entity_decode', 8 );

		return $content;
	}

	/**
	 * Modify the new forum reply notification email to use HTML.
	 *
	 * Works only for bbPress 1.x legacy forums installed in groups.
	 * Requires BuddyPress Group Email Subscription.
	 *
	 * @param string $retval The original email message.
	 * @param object $activity Data about the activity item. Note: This
	 *        is not a real BP activity item, but one faked by BPGES.
	 * @param object $post The bbPress 1.x topic generated from {@link get_topic()}.
	 * @param object $group The BP_Groups_Group object.
	 * @return string The modified email content containing HTML if available.
	 */
	function use_html_for_legacy_forum_reply( $retval, $activity, $topic, $group ) {
		$group_url = bp_get_group_permalink( $group );
		$topic_url = trailingslashit( $group_url . 'forum/topic/' . $topic->topic_slug );
		$view_url  = $activity->primary_link;

		// if group is not public, use login URL to verify authentication and for
		// easier redirection after logging in
		if ( $group->status != 'public' ) {
			$view_url = ass_get_login_redirect_url( $activity->primary_link );
		}

		$content = sprintf( __(
'%1$s replied to the forum topic %2$s in the group %3$s:

<blockquote>%4$s</blockquote>

%5$s', 'buddypress' ),
			bp_core_get_userlink( $activity->user_id ),
			sprintf( '<a href="%s">%s</a>', $topic_url, $topic->title ),
			sprintf( '<a href="%s">%s</a>', $group_url, $group->name ),
			$activity->content,
			sprintf( '<a href="%s">%s</a>', $view_url, __( 'View/Reply', 'buddypress' ) )
		);

		// Don't let GES strip goodies
		remove_filter( 'ass_clean_content', 'strip_tags', 4 );
		remove_filter( 'ass_clean_content', 'ass_convert_links', 6 );
		remove_filter( 'ass_clean_content', 'ass_html_entity_decode', 8 );

		return $content;
	}

	/**
	 * BPGES: Reformat the email footer to use HTML for legacy forum posts.
	 *
	 * We basically have to regenerate the footer contents here.
	 *
	 * @param string $retval The current email contents
  	 * @param array $data {
	 *     Array of data.
	 *     @type string $message The full email content excluding footer
	 *     @type string $notice The email footer
	 *     @type int $user_id The user ID posting the content
	 *     @type string $subscription_type Email subscription type for the receiver of
	 *           the email.
	 *     @type string $content The unmodified post content
	 *     @type string $view_link The URL to the primary link for the content
	 *     @type string $settings_link The settings link for the receiver of the email.
	 * }
	 * @return string
	 */
	public function adjust_legacy_forum_post_footer( $retval, $data ) {

		$add_unsubscribe_links = false;
		$footer_links = array();

		switch( $data['subscription_type'] ) {
			// self-notifications
			case 'self_notify' :
				$footer = sprintf( __( 'You are currently receiving notifications for your own posts.  To disable these notifications, <a href="%s">login here</a> and uncheck "Receive notifications of your own posts"', 'wpbe-bp' ), $data['settings_link'] );

				break;

			// 'new topics' or 'all mail'
			case 'sub':
			case 'supersub':
				$add_unsubscribe_links = true;

				$footer = sprintf( __( 'Your email setting for this group is: ', 'wpbe-bp' ) . '<strong>' . ass_subscribe_translate( $data['subscription_type'] ) . '</strong>' );

				// new topics only
				if ( 'sub' == $data['subscription_type'] ) {
					$footer .= sprintf( __( ', therefore you will not receive replies to this topic. To get them, <a href="%s">click here</a> to view this topic, then click the "Follow this topic" button.', 'wpbe-bp' ), $data['view_link'] );

				// user's group setting is "All Mail"
				} else {
					$footer_links[] = sprintf( '<a href="%1$s">%2$s</a>',
						$data['settings_link'],
						__( 'Change Group Email Settings', 'wpbe-bp' )
					);
				}

				break;

			// manually subscribed to the topic
			case 'manual_topic' :
				$footer = sprintf( __( 'You are manually subscribed to this topic.  To unsubscribe, <a href="%s">click here</a> to view this topic, then click on the "Mute this topic" button.', 'wpbe-bp' ), $data['settings_link'] );

				break;

			// receive replies to topics you have started
			case 'replies_to_my_topic' :
				$footer  = sprintf( __( 'You are currently receiving notifications to topics that you have started.  To disable these notifications, <a href="%s">login here</a> and uncheck "A member replies in a forum topic you\'ve started"', 'bp-ass' ), $data['settings_link'] );


				break;

			// receive replies to replies you have made in a topic
			case 'replies_after_me_topic' :
				$footer  = sprintf( __( 'You are currently receiving notifications to topics that you have replied in.  To disable these notifications, <a href="%s">login here</a> and uncheck "A member replies after you in a forum topic"', 'bp-ass' ), $data['settings_link'] );

				break;
		}

		// add unsubscribe links if necessary
		if ( true === $add_unsubscribe_links ) {
			$footer_links[] = $this->get_bpges_group_unsubscribe_link( $data['user_id'] );

			if ( 'yes' == get_option( 'ass-global-unsubscribe-link' ) ) {
				$footer_links[] = $this->get_bpges_global_unsubscribe_link( $data['user_id'] );
			}
		}

		$footer_links = implode( ' &middot; ', $footer_links );

		$content = sprintf( __( '%1$s
<hr />
%2$s
%3$s' ),
			$data['content'],
			$footer,
			$footer_links
		);

		return apply_filters( 'wpbe_bp_ass_forum_email_content', $content, $footer, $footer_links, $data );
	}

	/**
	 * BPGES: Use unfiltered activity action for activity sendouts.
	 *
	 * Unfiltered action includes HTML.
	 *
	 * @param bool $retval
	 * @return bool
	 */
	public function use_html_for_ges_activity_action( $retval, $activity ) {
		return $activity->action;
	}

	/**
	 * BPGES: Clean activity subject.
	 *
	 * This needs to be done due to us overriding the action to use HTML in
	 * {@link WPBE_BP::use_html_for_ges_activity_action()}.
	 *
	 * @param string The email subject
	 * @return string
	 */
	public function clean_ges_activity_subject( $retval ) {
		return ass_clean_subject( $retval );
	}

	/**
	 * BPGES: Use HTML for group activity item email blasts.
	 *
	 * @param string $retval The original email message.
	 * @param BP_Activity_Activity $activity The activity item.
	 * @param string $action The activity action.
	 * @param BP_Groups_Group $group The group object.
	 * @return string The modified email content containing HTML if available.
	 */
	function use_html_for_ges_activity_content( $retval, $activity, $action, $group ) {
		$activity_permalink = ( isset( $activity->primary_link ) && $activity->primary_link != bp_core_get_user_domain( $activity->user_id ) ) ? $activity->primary_link : bp_get_group_permalink( $group );

		// if group is not public, use login URL to verify authentication and for
		// easier redirection after logging in
		if ( $group->status != 'public' ) {
			$activity_permalink = ass_get_login_redirect_url( $activity_permalink );
		}

		// no content, so only print the activity action
		if ( empty( $retval ) ) {
			$content = sprintf( __( '%1$s

%2$s', 'buddypress' ),
				$action,
				sprintf( '<a href="%s">%s</a>', $activity_permalink, __( 'View/Reply', 'buddypress' ) )
			);


		// print content as well
		} else {
			$content = sprintf( __( '%1$s

<blockquote>%2$s</blockquote>

%3$s', 'buddypress' ),
				$action,
				$retval,
				sprintf( '<a href="%s">%s</a>', $activity_permalink, __( 'View/Reply', 'buddypress' ) )
			);
		}

		// Don't let GES strip goodies
		remove_filter( 'ass_clean_content', 'strip_tags', 4 );
		remove_filter( 'ass_clean_content', 'ass_convert_links', 6 );
		remove_filter( 'ass_clean_content', 'ass_html_entity_decode', 8 );

		return $content;
	}

	/**
	 * BPGES: Reformat the email footer to use HTML for group activity items.
	 *
	 * @param string $retval The current email contents
  	 * @param array $data {
	 *     Array of data.
	 *     @type string $message The full email content excluding footer
	 *     @type string $notice The email footer
	 *     @type int $user_id The user ID posting the content
	 *     @type string $subscription_type Email subscription type for the receiver of
	 *           the email.
	 *     @type string $content The unmodified post content
	 *     @type string $settings_link The settings link for the receiver of the email.
	 * }
	 * @return string
	 */
	public function adjust_ges_activity_email_footer( $retval, $data ) {
		$content = '';
		$footer  = '';
		$footer_links = array();

		switch( $data['subscription_type'] ) {
			// self-notifications
			case 'self_notify' :
				$footer = sprintf( __( 'You are currently receiving notifications for your own posts.  To disable these notifications, <a href="%s">login here</a> and uncheck "Receive notifications of your own posts"', 'wpbe-bp' ), $data['settings_link'] );

				$content = sprintf( __( '%1$s
<hr />
%2$s' ),
					$data['content'],
					$footer
				);

				break;

			// 'new topics' or 'all mail'
			case 'sub':
			case 'supersub':
				$footer = sprintf( __( 'Your email setting for this group is: ', 'wpbe-bp' ) . '<strong>' . ass_subscribe_translate( $data['subscription_type'] ) . '</strong>' );

				$footer_links[] = sprintf( '<a href="%1$s">%2$s</a>',
					$data['settings_link'],
					__( 'Change Group Email Settings', 'wpbe-bp' )
				);

				$footer_links[] = $this->get_bpges_group_unsubscribe_link( $data['user_id'] );

				if ( 'yes' == get_option( 'ass-global-unsubscribe-link' ) ) {
					$footer_links[] = $this->get_bpges_global_unsubscribe_link( $data['user_id'] );
				}

				$footer_links = implode( ' &middot; ', $footer_links );

				$content = sprintf( __( '%1$s
<hr />
%2$s
%3$s' ),
					$data['content'],
					$footer,
					$footer_links
				);

				break;
		}

		return apply_filters( 'wpbe_bp_ass_activity_email_content', $content, $footer, $footer_links, $data );
	}

	/**
	 * BPGES: Get the HTML unsubscribe link for a group including anchor text.
	 *
	 * We can't use {@link ass_group_unsubscribe_links()} because that includes
	 * miscellaneous strings.
	 *
	 * @param int $user_id The user ID to get the unsubscribe link for.
	 * @return string
	 */
	protected function get_bpges_group_unsubscribe_link( $user_id ) {
		$userdomain = bp_core_get_user_domain( $user_id );
		$group_id   = bp_get_current_group_id();
		$group_link = "$userdomain?bpass-action=unsubscribe&group={$group_id}&access_key=" . md5( "{$group_id}{$user_id}unsubscribe" . wp_salt() );

		return sprintf( '<a href="%1$s" title="%2$s">%3$s</a>',
			$group_link,
			__( 'To disable all notifications for this group only, click on this link', 'wpbe-bp' ),
			__( 'Unsubscribe from this group', 'wpbe-bp' )
		);
	}

	/**
	 * BPGES: Get the global unsubscribe link including anchor text.
	 *
	 * We can't use {@link ass_group_unsubscribe_links()} because that includes
	 * miscellaneous strings.
	 *
	 * @param int $user_id The user ID to get the global unsubscribe link for.
	 * @return string
	 */
	protected function get_bpges_global_unsubscribe_link( $user_id ) {
		$userdomain  = bp_core_get_user_domain( $user_id );
		$global_link = "$userdomain?bpass-action=unsubscribe&access_key=" . md5( "{$user_id}unsubscribe" . wp_salt() );

		return sprintf( '<a href="%1$s" title="%2$s">%3$s</a>',
			$global_link,
			__( 'To disable notifications from all group, click on this link', 'wpbe-bp' ),
			__( 'Unsubscribe from all groups', 'wpbe-bp' )
		);
	}

	/** WPBE-BP ************************************************************/

	/**
	 * Remove brackets around password reset link before converting over to HTML.
	 *
	 * The password reset link is wrapped around brackets:
	 * eg. <http://example.com/wp-login.php?action=rp&key...>
	 *
	 * This conflicts with our HTML convertion because our converter thinks this
	 * is an actual HTML tag when it isn't.
	 *
	 * @param  string $retval The body text before HTML conversion
	 * @return string
	 */
	function password_reset_fix( $retval ) {
		// determine if we are looking at a password reset email
		$login_pos = strpos( $retval, 'wp-login.php?action=rp&key' );
		if ( false === $login_pos ) {
			return $retval;
		}

		// Remove the opening and closing brackets
		$lbracket_pos = strrpos( $retval, '<', -( strlen( $retval ) - $login_pos ) );
		$text = substr_replace( $retval, '', $lbracket_pos, 1 );

		$rbracket_pos = strrpos( $text, '>' );
		$text = substr_replace( $text, '', $rbracket_pos, 1 );

		return $text;
	}

	/**
	 * In WP Better Emails, we still need to generate a plain-text body.
	 *
	 * Since we're now using HTML for emails, we should convert the HTML over to
	 * plain-text.
	 *
	 * Uses the html2text functions from the {@link http://openiaml.org/ IAML Modelling Platform}
	 * by {@link mailto:j.m.wright@massey.ac.nz Jevon Wright}.
	 *
	 * Licensed under the Eclipse Public License v1.0:
	 * {@link http://www.eclipse.org/legal/epl-v10.html}
	 *
	 * The functions have been modified by me to better support other elements
	 * like <img> and <li>.
	 *
	 * @param str $content The original email content
	 *
	 * @return str The modified email content converted to plain-text.
	 */
	function convert_html_to_plaintext( $content ) {
		if ( empty( $content ) ) {
			return $content;
		}

		// @todo perhaps load this library at load time instead of during sendouts?
		if ( ! function_exists( 'convert_html_to_text' ) ) {
			require( dirname( __FILE__ ) . '/functions.html2text.php' );
		}

		// suppress warnings when using DOMDocument
		// this addresses issues with WPBE-BP failing to parse
		if ( function_exists( 'libxml_use_internal_errors' ) ) {
			libxml_use_internal_errors( true );
		}

		add_filter( 'wpbe_html_to_plaintext', 'convert_html_to_text' );

		// 'wpbe_html_to_plaintext' is a custom filter by this plugin
		return apply_filters( 'wpbe_html_to_plaintext', $content );
	}

	/**
	 * If a user in BuddyPress has selected to receive emails in plain-text only,
	 * set up PHPMailer to use plain-text.
	 *
	 * @see WPBE_BP::set_wp_mail_content_type()
	 *
	 * @param obj $phpmailer The PHPMailer object
	 */
	function send_plaintext_only( $phpmailer ) {
		global $wp_better_emails;

		// Add plain-text template to message
		$phpmailer->Body = $wp_better_emails->set_email_template( $phpmailer->Body, 'plaintext_template' );

		// The 'wpbe_plaintext_body' filter does the actual conversion to plain-text
		$phpmailer->Body = apply_filters( 'wpbe_plaintext_body', $wp_better_emails->template_vars_replacement( $phpmailer->Body ) );

		// wipe out the alt body
		$phpmailer->AltBody = '';

		// set HTML to false to be extra-safe!
		$phpmailer->IsHTML( false );

	}

	/**
	 * Renders notification fields in BuddyPress' settings area
	 */
	function screen() {
		if ( !$type = bp_get_user_meta( bp_displayed_user_id(), 'notification_html_email', true ) )
			$type = 'yes';
	?>

		<div id="email-type-notification">

			<h3><?php _e( 'Email Type', 'wpbe-bp' ); ?></h3>

			<p><?php _e( 'Choose between HTML or plain-text when receiving email notifications.', 'wpbe-bp' ); ?></p>

			<table class="notification-settings">
				<thead>
					<tr>
						<th class="icon">&nbsp;</th>
						<th class="title">&nbsp;</th>
						<th class="yes"><?php _e( 'Yes', 'buddypress' ) ?></th>
						<th class="no"><?php _e( 'No', 'buddypress' )?></th>
					</tr>
				</thead>

				<tbody>
					<tr>
						<td>&nbsp;</td>
						<td><?php _e( 'Use HTML email?', 'wpbe-bp' ); ?></td>
						<td class="yes"><input type="radio" name="notifications[notification_html_email]" value="yes" <?php checked( $type, 'yes', true ); ?>/></td>
						<td class="no"><input type="radio" name="notifications[notification_html_email]" value="no" <?php checked( $type, 'no', true ); ?>/></td>
					</tr>
				</tbody>
			</table>
		</div>
	<?php
	}

	/**
	 * Add a notice saying that WP Better Emails doesn't exist.
	 *
	 * We also add a quick activation / installation link for WP Better Emails depending on that plugin's state.
	 *
	 * @see Theme_Plugin_Dependency
	 */
	function get_wpbe() {
		$wpbe = new Theme_Plugin_Dependency( 'wp-better-emails', 'http://wordpress.org/extend/plugins/wp-better-emails/' );
	?>
		<div class="error">
			<p><?php printf( __( '%s requires the %s plugin to be activated.', 'wpbe-bp' ), '<strong>WP Better Emails for BuddyPress</strong>', '<strong>WP Better Emails</strong>' ); ?></p>

			<?php if ( $wpbe->check() ) : ?>
				<p><a href="<?php echo $wpbe->activate_link(); ?>"><?php _e( 'Activate it now!', 'wpbe-bp' ); ?></a></p>
			<?php elseif ( $install_link = $wpbe->install_link() ) : ?>
				<p><a href="<?php echo $install_link; ?>"><?php _e( 'Intsall it now!', 'wpbe-bp' ); ?></a></p>
			<?php endif; ?>
		</div>
	<?php
	}

	/**
	 * Determine if we're using the newer version of WP Better Emails.
	 */
	function is_newer_version() {
		global $wp_better_emails;

		if ( is_callable( array( $wp_better_emails, 'plaintext_template_editor' ) ) )
			return true;

		return false;
	}
}

// Wind it up!
add_action( 'bp_init', array( 'WPBE_BP', 'init' ) );

if ( ! class_exists( 'Theme_Plugin_Dependency' ) ) {
	/**
	 * Simple class to let themes add dependencies on plugins in ways they might find useful.
	 *
	 * Thanks Otto!
	 *
	 * @link http://ottopress.com/2012/themeplugin-dependencies/
	 */
	class Theme_Plugin_Dependency {
		// input information from the theme
		var $slug;
		var $uri;

		// installed plugins and uris of them
		private $plugins; // holds the list of plugins and their info
		private $uris; // holds just the URIs for quick and easy searching

		// both slug and PluginURI are required for checking things
		function __construct( $slug, $uri ) {
			$this->slug = $slug;
			$this->uri = $uri;
			if ( empty( $this->plugins ) )
				$this->plugins = get_plugins();
			if ( empty( $this->uris ) )
				$this->uris = wp_list_pluck($this->plugins, 'PluginURI');
		}

		// return true if installed, false if not
		function check() {
			return in_array($this->uri, $this->uris);
		}

		// return true if installed and activated, false if not
		function check_active() {
			$plugin_file = $this->get_plugin_file();
			if ($plugin_file) return is_plugin_active($plugin_file);
			return false;
		}

		// gives a link to activate the plugin
		function activate_link() {
			$plugin_file = $this->get_plugin_file();
			if ($plugin_file) return wp_nonce_url(self_admin_url('plugins.php?action=activate&plugin='.$plugin_file), 'activate-plugin_'.$plugin_file);
			return false;
		}

		// return a nonced installation link for the plugin. checks wordpress.org to make sure it's there first.
		function install_link() {
			include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

			$info = plugins_api('plugin_information', array('slug' => $this->slug ));

			if ( is_wp_error( $info ) )
				return false; // plugin not available from wordpress.org

			return wp_nonce_url(self_admin_url('update.php?action=install-plugin&plugin=' . $this->slug), 'install-plugin_' . $this->slug);
		}

		// return array key of plugin if installed, false if not, private because this isn't needed for themes, generally
		private function get_plugin_file() {
			return array_search($this->uri, $this->uris);
		}
	}
}

?>
