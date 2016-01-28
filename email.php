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
 * Version details.
 *
 * @package    tool_email
 * @copyright  2016 Brendan Heywood <brendan@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/clilib.php');

list($options, $unrecognized) = cli_get_params(array(
    'dryrun' => false,
    'from' => $CFG->supportemail,
    'help' => false,
    'method' => 'email',
    'subject' => '',
    'to' => 'ping@tools.mxtoolbox.com',
), array(
    'd' => 'dryrun',
    'f' => 'from',
    'h' => 'help',
    'm' => 'method',
    's' => 'subject',
    't' => 'to',
));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized), 2);
}

if ($options['help'] || empty($options['subject'])) {

    $help = "Send an email to someone, from someone, using the mooodle libs.

Options:
-h, --help     Print out this help
-t, --to       To   email address (defaults to {$options['to']})
-f, --from     From email address (defaults to {$options['from']})
-s, --subject  Subject is required
-m, --method   'email' (default) or 'message' which uses the Message API

Example:
\$php email.php -s='Test subject'
\$php email.php -s=Test -t=to@example.com -f=from@moodle.com
";

    echo $help;
    exit(0);
}

$to = (object)array(
    'id' => 1,
    'auth' => 'manual',
    'email' => $options['to'],
    'firstname' => null,
    'lastname' => null,
    'deleted' => 0,
    'emailstop' => 0,
    'suspended' => 0,
    'maildisplay' => true,
);
$from = $to;
$from->email = $options['from'];

$subject = $options['subject'];
$body = 'Test email';

if ($options['dryrun']) {
    echo "Dry run: email from {$options['from']} to {$options['to']}\n";
} else {

    switch ($options['method']) {

        case 'email':
            email_to_user($to, $from, $subject, $body);
            print "email_to_user(from: {$options['from']}, to:{$options['to']}, subject, body);\n";
            break;

        case 'message':

            $eventdata = new \stdClass();
            $eventdata->component           = 'tool_email';
            $eventdata->name                = 'email';
            $eventdata->userfrom            = $from;
            $eventdata->userto              = $to;
            $eventdata->subject             = $subject;
            $eventdata->fullmessage         = $body;
            $eventdata->fullmessageformat   = FORMAT_PLAIN;
            $eventdata->fullmessagehtml     = $body;
            $eventdata->smallmessage        = $body;
            $eventdata->notification        = 1;

            if (message_send($eventdata)) {
                print "---> Success notification sent to {$to->email}.";
            } else {
                print "---> Unable to send notification.";
            }
            break;

        default:
            print "Unkown method {$options['method']}\n";
    }

}

