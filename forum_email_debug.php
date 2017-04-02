<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Debug the cron forum_email task.
 *
 * This is a dry run copy of mod/forum/lib forum_cron() with extra debugging output.
 *
 * @package    tool_email
 * @author     Nicholas Hoobin <nicholashoobin@catalyst-au.net>
 * @copyright  2017 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');

// Now get cli options.
list($options, $unrecognized) = cli_get_params(
    array(
        'help' => false,
        'pid' => false,
        'uid' => false,
        'start' => false,
        'days' => 1,
        'force' => false,
    ),
    array(
        'h' => 'help',
        'p' => 'pid',
        'u' => 'uid',
        's' => 'start',
        'd' => 'days',
        'f' => 'force',
    )
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

$help = "Debug a post id.

Options:
 -h, --help     Print out this help
 -p  --pid      The post ID.
 -u  --uid      The user ID.
 -s  --start    The time to start when searching for emails. (Optional)
 -d  --days     The number of days to extend the search time. (Optional)
 -f  --force    When obtaining the list of posts, obtiain all posts, not just unmailed.
Example:
\$sudo -u www-data /usr/bin/php admin/tool/email/forum_email_debug.php --pid=1234 --uid=5678 --all
";

if ($options['help'] || !$options['pid']) {
    echo $help;
    die;
}

$pid = $options['pid'];
$uid = $options['uid'];
$start = $options['start'];
$days = $options['days'];
$force = $options['force'];

debug_post_id($pid, $uid, $start, $days, $force);

function debug_post_id($postid, $userid, $start, $days, $force) {
    global $CFG;

    $timenow   = time();
    $endtime   = $timenow - $CFG->maxeditingtime;

    if (empty($start)) {
        $starttime = $endtime - ((24 * $days) * 3600);
    } else {
        $starttime = $start;
    }

    if ($force) {
        $endtime += $CFG->maxeditingtime;
        $posts = get_all_posts($starttime, $endtime, $timenow);
    } else {
        $posts = forum_get_unmailed_posts($starttime, $endtime, $timenow);
    }

    $found = false;

    if ($posts) {
        foreach ($posts as $pid => $post) {

            // We want to check this $postid only.
            if ($pid == $postid) {
                $found = true;
                examine_post($post, $userid);
            }
        }
    } else {
        mtrace('No posts found in the range ' . userdate($starttime) . ' <> ' . userdate($endtime));
    }

    if ($found === false) {
        mtrace('Postid ' . $postid . ' not found in range ' . userdate($starttime) . ' <> ' . userdate($endtime));
    }
}

/**
 * Returns a list of all posts from the specific date range.
 *
 * This is a modified version of mod/forum/lib.php forum_get_unmailed_posts().
 *
 * @param int $starttime posts created after this time
 * @param int $endtime posts created before this
 * @param int $now used for timed discussions only
 * @return array
 */
function get_all_posts($starttime, $endtime, $now=null) {
    global $CFG, $DB;

    $params = array();
    $params['ptimestart'] = $starttime;
    $params['ptimeend'] = $endtime;
    $params['mailnow'] = 1;

    if (!empty($CFG->forum_enabletimedposts)) {
        if (empty($now)) {
            $now = time();
        }
        $selectsql = "AND (p.created >= :ptimestart OR d.timestart >= :pptimestart)";
        $params['pptimestart'] = $starttime;
        $timedsql = "AND (d.timestart < :dtimestart AND (d.timeend = 0 OR d.timeend > :dtimeend))";
        $params['dtimestart'] = $now;
        $params['dtimeend'] = $now;
    } else {
        $timedsql = "";
        $selectsql = "AND p.created >= :ptimestart";
    }

    return $DB->get_records_sql("SELECT p.*, d.course, d.forum
                                 FROM {forum_posts} p
                                 JOIN {forum_discussions} d ON d.id = p.discussion
                                 WHERE 1=1 $selectsql
                                 AND (p.created < :ptimeend OR p.mailnow = :mailnow)
                                 $timedsql
                                 ORDER BY p.modified ASC", $params);
}

function examine_post($post, $userid = null) {
    global $DB;

    // Get the list of forum subscriptions for per-user per-forum maildigest settings.
    $digestsset = $DB->get_recordset('forum_digests', null, '', 'id, userid, forum, maildigest');
    $digests = array();
    foreach ($digestsset as $thisrow) {
        if (!isset($digests[$thisrow->forum])) {
            $digests[$thisrow->forum] = array();
        }
        $digests[$thisrow->forum][$thisrow->userid] = $thisrow->maildigest;
    }
    $digestsset->close();

    // caches
    $discussions   = array();
    $forums        = array();
    $courses       = array();
    $coursemodules = array();
    $users         = array();
    $userscount    = 0;

    $pid = $post->id;

    mtrace('Processing post ' . $post->id . ' ' . $post->subject);

    $discussionid = $post->discussion;
    if (!isset($discussions[$discussionid])) {
        if ($discussion = $DB->get_record('forum_discussions', array('id' => $post->discussion))) {
            $discussions[$discussionid] = $discussion;
            \mod_forum\subscriptions::fill_subscription_cache($discussion->forum);
            \mod_forum\subscriptions::fill_discussion_subscription_cache($discussion->forum);

        } else {
            mtrace('Could not find discussion ' . $discussionid);
            return;
        }
    }

    $forumid = $discussions[$discussionid]->forum;
    if (!isset($forums[$forumid])) {
        if ($forum = $DB->get_record('forum', array('id' => $forumid))) {
            $forums[$forumid] = $forum;
        } else {
            mtrace('Could not find forum '.$forumid);
            return;
        }
    }

    $courseid = $forums[$forumid]->course;
    if (!isset($courses[$courseid])) {
        if ($course = $DB->get_record('course', array('id' => $courseid))) {
            $courses[$courseid] = $course;
        } else {
            mtrace('Could not find course '.$courseid);
            return;
        }
    }

    if (!isset($coursemodules[$forumid])) {
        if ($cm = get_coursemodule_from_instance('forum', $forumid, $courseid)) {
            $coursemodules[$forumid] = $cm;
        } else {
            mtrace('Could not find course module for forum '.$forumid);
            return;
        }
    }

    // Caching subscribed users of each forum.
    if (!isset($subscribedusers[$forumid])) {
        $modcontext = context_module::instance($coursemodules[$forumid]->id);
        if ($subusers = \mod_forum\subscriptions::fetch_subscribed_users($forums[$forumid], 0, $modcontext, 'u.*', true)) {

            foreach ($subusers as $postuser) {
                // this user is subscribed to this forum
                $subscribedusers[$forumid][$postuser->id] = $postuser->id;
                $userscount++;
                if ($userscount > FORUM_CRON_USER_CACHE) {
                    // Store minimal user info.
                    $minuser = new stdClass();
                    $minuser->id = $postuser->id;
                    $users[$postuser->id] = $minuser;
                } else {
                    // Cache full user record.
                    forum_cron_minimise_user_record($postuser);
                    $users[$postuser->id] = $postuser;
                }
            }
            // Release memory.
            unset($subusers);
            unset($postuser);
        }
    }
    $mailcount[$pid] = 0;
    $errorcount[$pid] = 0;

    if (!empty($userid)) {
        $userto = $users[$userid];
        $users = [];
        $users[$userid] = $userto;
    }

    foreach ($users as $userto) {

        if (!array_key_exists($userto->id, $users)) {
            mtrace('User ' . $userto->id . ' is not associated with the post ' . $pid);
            continue;
        }

        // Init user caches - we keep the cache for one cycle only, otherwise it could consume too much memory.
        if (isset($userto->username)) {
            $userto = clone($userto);
        } else {
            $userto = $DB->get_record('user', array('id' => $userto->id));
            forum_cron_minimise_user_record($userto);
        }
        $userto->viewfullnames = array();
        $userto->canpost       = array();
        $userto->markposts     = array();

        mtrace('Processing user ' . $userto->id . ' ' . $userto->email);

        // Setup this user so that the capabilities are cached, and environment matches receiving user.
        cron_setup_user($userto);

        // Reset the caches.
        foreach ($coursemodules as $forumid => $unused) {
            $coursemodules[$forumid]->cache       = new stdClass();
            $coursemodules[$forumid]->cache->caps = array();
            unset($coursemodules[$forumid]->uservisible);
        }

        $discussion = $discussions[$post->discussion];
        $forum      = $forums[$discussion->forum];
        $course     = $courses[$forum->course];
        $cm         =& $coursemodules[$forum->id];

        // Do some checks to see if we can bail out now.

        // Only active enrolled users are in the list of subscribers.
        // This does not necessarily mean that the user is subscribed to the forum or to the discussion though.
        if (!isset($subscribedusers[$forum->id][$userto->id])) {
            // The user does not subscribe to this forum.
            mtrace('User ' . $userto->id . ' ' . $userto->email . '. User does not subscribe to this forum.');
            continue;
        }

        if (!\mod_forum\subscriptions::is_subscribed($userto->id, $forum, $post->discussion, $coursemodules[$forum->id])) {
            // The user does not subscribe to this forum, or to this specific discussion.
            mtrace('User ' . $userto->id . ' ' . $userto->email . '. User does not subscribe to this forum or this specific discussion.');
            continue;
        }

        if ($subscriptiontime = \mod_forum\subscriptions::fetch_discussion_subscription($forum->id, $userto->id)) {
            // Skip posts if the user subscribed to the discussion after it was created.
            if (isset($subscriptiontime[$post->discussion]) && ($subscriptiontime[$post->discussion] > $post->created)) {
                mtrace('User ' . $userto->id . ' ' . $userto->email . '. User was subscribed after the post was created');
                continue;
            }
        }

        // Don't send email if the forum is Q&A and the user has not posted.
        // Initial topics are still mailed.
        if ($forum->type == 'qanda' && !forum_get_user_posted_time($discussion->id, $userto->id) && $pid != $discussion->firstpost) {
            mtrace('User ' . $userto->id . ' ' . $userto->email . 'Did not email because user has not posted in the discussion. Q and A.');
            continue;
        }

        // Get info about the sending user.
        if (array_key_exists($post->userid, $users)) {
            // We might know the user already.
            $userfrom = $users[$post->userid];
            if (!isset($userfrom->idnumber)) {
                // Minimalised user info, fetch full record.
                $userfrom = $DB->get_record('user', array('id' => $userfrom->id));
                forum_cron_minimise_user_record($userfrom);
            }

        } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
            forum_cron_minimise_user_record($userfrom);
            // Fetch only once if possible, we can add it to user list, it will be skipped anyway.
            if ($userscount <= FORUM_CRON_USER_CACHE) {
                $userscount++;
                $users[$userfrom->id] = $userfrom;
            }
        } else {
            mtrace('User ' . $userto->id . ' ' . $userto->email . '. Could not find user ' . $post->userid . ', author of post ' . $post->id . '. Unable to send message.');
            continue;
        }

        // Note: If we want to check that userto and userfrom are not the same person this is probably the spot to do it.

        // Setup global $COURSE properly - needed for roles and languages.
        cron_setup_user($userto, $course);

        // Fill caches.
        if (!isset($userto->accessdata)) {
            $userto->accessdata = get_user_forumcron_access($userto->id);
        }

        if (!isset($userto->viewfullnames[$forum->id])) {
            $modcontext = context_module::instance($cm->id);
            $userto->viewfullnames[$forum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
        }
        if (!isset($userto->canpost[$discussion->id])) {
            $modcontext = context_module::instance($cm->id);
            $userto->canpost[$discussion->id] = forum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
        }
        if (!isset($userfrom->groups[$forum->id])) {
            if (!isset($userfrom->groups)) {
                $userfrom->groups = array();
                if (isset($users[$userfrom->id])) {
                    $users[$userfrom->id]->groups = array();
                }
            }
            $userfrom->groups[$forum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
            if (isset($users[$userfrom->id])) {
                $users[$userfrom->id]->groups[$forum->id] = $userfrom->groups[$forum->id];
            }
        }

        // Make sure groups allow this user to see this email.
        if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {
            // Groups are being used.
            if (!groups_group_exists($discussion->groupid)) {
                // Can't find group - be safe and don't this message.
                mtrace('User ' . $userto->id . ' ' . $userto->email . '. Could not find group.');
                continue;
            }

            if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $modcontext)) {
                // Do not send posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS.
                mtrace('User ' . $userto->id . ' ' . $userto->email . '. Do not send posts from other gorups when in SEPARATEGROUPS or VISIBLEGROUPS.');
                continue;
            }
        }

        // Make sure we're allowed to see the post.
        if (!forum_user_can_see_post($forum, $discussion, $post, null, $cm)) {
            mtrace('User ' . $userto->id .' can not see ' . $post->id . '. Not sending message.');
            continue;
        }

        // OK so we need to send the email.

        // Does the user want this post in a digest?  If so postpone it for now.
        $maildigest = forum_get_user_maildigest_bulk($digests, $userto, $forum->id);

        if ($maildigest > 0) {
            // This user wants the mails to be in digest form.
            $queue = new stdClass();
            $queue->userid       = $userto->id;
            $queue->discussionid = $discussion->id;
            $queue->postid       = $post->id;
            $queue->timemodified = $post->created;
            mtrace('User ' . $userto->id .' can not see ' . $post->id . '. Email queued, user wants emails as digest.');
            continue;
        }

        $now = \DateTime::createFromFormat('U.u', microtime(true));
        if (empty($now)) {
            $now = \DateTime::createFromFormat('U', time());
        }

        mtrace('Would send post ' . $post->id
            . ' from ' . date('Y-m-d H:i:s', $post->created)
            . ' to user ' . $userto->id
            . ' after delay of ' . (time() - $post->created)
            . ' seconds at ' . $now->format('Y-m-d H:i:s.u')
            . ' : ' . $post->subject);

        $mailcount[$post->id]++;
    }

    if (empty($userid)) {
        mtrace($mailcount[$post->id]." users could be sent post $post->id, '$post->subject'");
    }

}

/**
 * This function was created to dramatically
 * decrease the time taken to run the forum_cron
 * It is the same as get_user_access_sitewide but
 * only for the capabilities the forum_cron uses
 */
function get_user_forumcron_access($userid) {
    global $CFG, $DB, $ACCESSLIB_PRIVATE, $USER;

    unset($USER->access);

    if ($userid != 0) {
        if ($userid == $USER->id and isset($USER->deleted)) {
            // this prevents one query per page, it is a bit of cheating,
            // but hopefully session is terminated properly once user is deleted
            if ($USER->deleted) {
                return false;
            }
        } else {
            if (!context_user::instance($userid, IGNORE_MISSING)) {
                // no user context == invalid userid
                return false;
            }
        }
    }

    // raparents collects paths & roles we need to walk up the parenthood to build the minimal rdef
    $raparents = array();
    $accessdata = get_empty_accessdata();

    // start with the default role
    if (!empty($CFG->defaultuserroleid)) {
        $syscontext = context_system::instance();
        $accessdata['ra'][$syscontext->path][(int)$CFG->defaultuserroleid] = (int)$CFG->defaultuserroleid;
        $raparents[$CFG->defaultuserroleid][$syscontext->id] = $syscontext->id;
    }

    // load the "default frontpage role"
    if (!empty($CFG->defaultfrontpageroleid)) {
        $frontpagecontext = context_course::instance(get_site()->id);
        if ($frontpagecontext->path) {
            $accessdata['ra'][$frontpagecontext->path][(int)$CFG->defaultfrontpageroleid] = (int)$CFG->defaultfrontpageroleid;
            $raparents[$CFG->defaultfrontpageroleid][$frontpagecontext->id] = $frontpagecontext->id;
        }
    }

    // preload every assigned role at and above course context
    $sql = "SELECT ctx.path, ra.roleid, ra.contextid
              FROM {role_assignments} ra
              JOIN {context} ctx
                   ON ctx.id = ra.contextid
         LEFT JOIN {block_instances} bi
                   ON (ctx.contextlevel = ".CONTEXT_BLOCK." AND bi.id = ctx.instanceid)
         LEFT JOIN {context} bpctx
                   ON (bpctx.id = bi.parentcontextid)
             WHERE ra.userid = :userid
                   AND (ctx.contextlevel <= ".CONTEXT_COURSE." OR bpctx.contextlevel < ".CONTEXT_COURSE.")";
    $params = array('userid'=>$userid);
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach ($rs as $ra) {
        // RAs leafs are arrays to support multi-role assignments...
        $accessdata['ra'][$ra->path][(int)$ra->roleid] = (int)$ra->roleid;
        $raparents[$ra->roleid][$ra->contextid] = $ra->contextid;
    }
    $rs->close();

    if (empty($raparents)) {
        $ACCESSLIB_PRIVATE->accessdatabyuser[$userid] = $accessdata;
        $USER->access = $ACCESSLIB_PRIVATE->accessdatabyuser[$userid];
        return true;
    }

    // now get overrides of interesting roles in all interesting child contexts
    // hopefully we will not run out of SQL limits here,
    // users would have to have very many roles at/above course context...
    $sqls = array();
    $params = array();

    static $cp = 0;
    foreach ($raparents as $roleid=>$ras) {
        $cp++;
        list($sqlcids, $cids) = $DB->get_in_or_equal($ras, SQL_PARAMS_NAMED, 'c'.$cp.'_');
        $params = array_merge($params, $cids);
        $params['r'.$cp] = $roleid;
        $params['viewfullnames'.$cp] = 'moodle/site:viewfullnames';
        $params['accessallgroups'.$cp] = 'moodle/site:accessallgroups';
        $params['viewdiscussion'.$cp] = 'mod/forum:viewdiscussion';
        $params['viewdetails'.$cp] = 'moodle/user:viewdetails';
        $params['readuserposts'.$cp] = 'moodle/user:readuserposts';
        $params['viewqandawithoutposting'.$cp] = 'mod/forum:viewqandawithoutposting';
        $params['replynews'.$cp] = 'mod/forum:replynews';
        $params['replypost'.$cp] = 'mod/forum:replypost';
        $params['viewhiddenactivities'.$cp] = 'moodle/course:viewhiddenactivities';
        $params['view'.$cp] = 'moodle/course:view';
        $sqls[] = "(SELECT ctx.path, rc.roleid, rc.capability, rc.permission
                     FROM {role_capabilities} rc
                     JOIN {context} ctx
                          ON (ctx.id = rc.contextid 
                              AND rc.capability IN (:viewfullnames{$cp},:accessallgroups{$cp},:viewdiscussion{$cp},
                                                    :viewdetails{$cp},:readuserposts{$cp},:viewqandawithoutposting{$cp},
                                                    :replynews{$cp},:replypost{$cp},:viewhiddenactivities{$cp},:view{$cp}))
                     JOIN {context} pctx
                          ON (pctx.id $sqlcids
                              AND (ctx.id = pctx.id
                                   OR ctx.path LIKE ".$DB->sql_concat('pctx.path',"'/%'")."
                                   OR pctx.path LIKE ".$DB->sql_concat('ctx.path',"'/%'")."))
                LEFT JOIN {block_instances} bi
                          ON (ctx.contextlevel = ".CONTEXT_BLOCK." AND bi.id = ctx.instanceid)
                LEFT JOIN {context} bpctx
                          ON (bpctx.id = bi.parentcontextid)
                    WHERE rc.roleid = :r{$cp}
                          AND (ctx.contextlevel <= ".CONTEXT_COURSE." OR bpctx.contextlevel < ".CONTEXT_COURSE.")
                   )";
    }

    $rs = $DB->get_recordset_sql(implode("\nUNION\n", $sqls). "ORDER BY capability", $params);
    foreach ($rs as $rd) {
        $k = $rd->path.':'.$rd->roleid;
        $accessdata['rdef'][$k][$rd->capability] = (int)$rd->permission;
    }
    $rs->close();

    // share the role definitions
    foreach ($accessdata['rdef'] as $k=>$unused) {
        if (!isset($ACCESSLIB_PRIVATE->rolepermissions[$k])) {
            $ACCESSLIB_PRIVATE->rolepermissions[$k] = $accessdata['rdef'][$k];
        }
        $accessdata['rdef_count']++;
        $accessdata['rdef'][$k] =& $ACCESSLIB_PRIVATE->rolepermissions[$k];
    }

    $ACCESSLIB_PRIVATE->accessdatabyuser[$userid] = $accessdata;
    $USER->access = $ACCESSLIB_PRIVATE->accessdatabyuser[$userid];
    return true;
}
