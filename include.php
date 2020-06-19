<?php
// TODO: does it make sense to keep timezone information?
date_default_timezone_set('UTC');

$db = new PDO('sqlite:' . $_SERVER['HOME'] . '/Library/Messages/chat.db');

$keys = array_keys(load_contacts());
// 'You' will be the first contact in the list.
$me = array_shift($keys);

// Load contacts from a file 'contacts.txt' in format '[number/email] [name]'.
function load_contacts() {
  static $data;
  if(!isset($data)) {
    $data = array();
    preg_match_all('/([^ ]+) (.+)/', file_get_contents('contacts.txt'), $matches);
    foreach($matches[1] as $i=>$key) {
      $data[trim($key)] = trim($matches[2][$i]);
    }
  }
  return $data;
}

// Get a contact link for for a certain ID (number/email).
function contact($id) {
  $data = load_contacts();

  if(preg_match('/.+@.+\..+/', $id)) {
    $href = 'mailto:' . $id;
  } else {
    $href = 'sms:' . $id;
  }

  if(array_key_exists($id, $data)) {
    return '<a href="' . $href . '" class="p-author h-card">' . $data[$id] . '</a>';
  } else {
    return '<a href="' . $href . '" class="p-author h-card">' . $id . '</a>';
  }
}

// Get the contact name for for a certain ID (number/email).
function contact_name($id) {
  $data = load_contacts();
  if(array_key_exists($id, $data)) {
    return $data[$id];
  } else {
    return $id;
  }
}

// Get all messages from a certain timestamp onwards.
// This could be used to resume archival.
// Returns for each message (id, timestamp, text, is_from_me, contact).
// Here 'is_from_me' is a 0/1 boolean indicating sent or recieved message,
// and contact is a numerical ID (from the 'handle' table). 
function query_messages_since(&$db, $timestamp) {
  return $db->query('SELECT m.ROWID, substr(date,1,9)+978307200 AS date,
    m.text, is_from_me, h.id AS contact
  FROM message m
    LEFT JOIN chat_message_join cm ON cm.message_id = m.ROWID
    LEFT JOIN chat_handle_join ch ON ch.chat_id = cm.chat_id
    LEFT JOIN handle h ON h.ROWID = ch.handle_id
  WHERE cache_roomnames IS NULL
    AND substr(date,1,9)+978307200 > ' . $timestamp . '
  ORDER BY date
  ');
}

// Gets the file in which a message is to be stored
// (i.e. '[contact]/[year]-[month].html').
function filename_for_message($contact, $ts) {
  global $message_dir;
  $folder = contact_name($contact);
  return $message_dir . $folder . '/' . date('Y-m', $ts) . '.html';
}

// Gets the folder in which an attachment is to be stored
// (i.e. '[contact]/[year]-[month]/').
function attachment_folder($contact, $ts, $relative=false) {
  global $message_dir;
  $folder = contact_name($contact);
  return ($relative ? '' : $message_dir . $folder . '/') . date('Y-m', $ts) . '/';
}

// Format a line describing a message for output to the html file.
function format_line($line, $attachments) {
  global $me;

  if($line['is_from_me'])
    $contact = $me;
  else
    $contact = $line['contact'];

  $attachments_html = '';

  if(count($attachments)) {
    foreach($attachments as $at) {
      $src = attachment_folder($line['contact'], $line['date'], true) .
        ($at['ROWID'] . '_' . $at['transfer_name']);
      $type = reset(explode('/', $at['mime_type'], 2));

      $default_html = '<br>Attachment: <a href="' . $src . '">' .
        $at['transfer_name'] . '</a>';

      switch ($type) {
        case 'image':
          $attachments_html .= '<img src="' . $src . '" class="u-photo">';
          break;
        case 'audio':
        // TODO: AMR files cannot be played in HTML audio element,
        //       so this is often useless. Either include a separate AMR player,
        //       or convert the audio files during archival (not preferred).
          $attachments_html .= '<audio controls src="' . $src . '" class="u-audio">' .
            $default_html . '</audio>';
          break;
        case 'video':
          $attachments_html .= '<video controls src="' . $src . '" class="u-video">' .
            $default_html . '</video>';
          break;
        default:
          $attachments_html .= $default_html;
          break;
      }
    }
  }

  return '<div class="h-entry">'
    . '<time class="dt-published" datetime="' . date('c', $line['date']) . '">' . date('Y-m-d H:i:s', $line['date']) . '</time> '
    . contact($contact)
    . ' <span class="e-content p-name">' . htmlentities(trim($line['text']))
    . $attachments_html
    . '</span>'
    . '</div>';
}

// Check whether the given message is already archived (identically).
// Used to prevent (identical) duplicates.
function entry_exists($line, $attachments, $fn) {
  if(!file_exists($fn)) return false;
  $file = file_get_contents($fn);
  return strpos($file, format_line($line, $attachments)) !== false;
}

// Outputs the header for the html files.
function html_template() {
  ob_start();
?>
<!DOCTYPE html>
<meta charset="utf-8">
<link type="text/css" rel="stylesheet" href="../style.css">
<?php
  return ob_get_clean();
}

