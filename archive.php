<?php
chdir(dirname(__FILE__));
include('include.php');

$opt = getopt(
  "v",
  ["verbose"]
);

$print_output = array_key_exists("v", $opt) ||
                array_key_exists("verbose", $opt);

// TODO: Set via optional argument.
$message_dir = 'messages/';

$last_fn = dirname(__FILE__).'/last.txt';
if(file_exists($last_fn)) {
  $last = file_get_contents($last_fn);
} else {
  $last = 0;
}

$query = query_messages_since($db, $last);
$last_timestamp = 0;

echo "Ready to archive messages. Press q once to pause or twice to quit.
  When running this file after quitting or finishing archival will resume
  from the next message. Press enter to start.\n";
fgets(STDIN);

// Create 'messages' directory if necessary.
if(!file_exists($message_dir)) {
  mkdir(dirname($fn));
}

// Copy stylesheet if necessary.
if(!file_exists($message_dir . 'style.css')) {
  copy('default-style.css', $message_dir . 'style.css');
}

$quit = false;
while(!$quit && $line = $query->fetch(PDO::FETCH_ASSOC)) {
  $fn = filename_for_message($line['contact'], $line['date']);
  
  // Print filename.
  if ($print_output) {
    echo $fn."\n";
  }

  // Create directory if necessary.
  if(!file_exists(dirname($fn))) {
    mkdir(dirname($fn));
  }

  // If file does not exist yet, write the template (header etc).
  if(!file_exists($fn)) {
    file_put_contents($fn, html_template());
  }

  // Get all attachments for a message.
  $attachment_query = $db->query('SELECT attachment.*
    FROM attachment 
    JOIN message_attachment_join ON message_attachment_join.attachment_id=attachment.ROWID
    WHERE message_attachment_join.message_id = ' . $line['ROWID']);
  $attachments = array();
  while($attachment = $attachment_query->fetch(PDO::FETCH_ASSOC)) {
    $attachments[] = $attachment;
  }

  // Check whether message has not been archived yet.
  if(!entry_exists($line, $attachments, $fn)) {
    // Write message to html file.
    $fp = fopen($fn, 'a');
    $log = format_line($line, $attachments);
    fwrite($fp, $log."\n");
    fclose($fp);

    // Print message details.
    if ($print_output) {
      echo date('c', $line['date']) . "\t" . $line['contact'] . "\t" . $line['text'] . "\n";
    }

    // Copy attachments to archive.
    foreach($attachments as $at) {
      $imgsrc = attachment_folder($line['contact'], $line['date']) .
        ($at['ROWID'] . '_' . $at['transfer_name']);
      if(!file_exists(dirname($imgsrc))) 
        mkdir(dirname($imgsrc));
      copy(str_replace('~/',$_SERVER['HOME'].'/',$at['filename']), $imgsrc);
    }
  }

  // Keep track of last archived message.
  if($line['date'] > $last_timestamp) {
    $last_timestamp = $line['date'];
  }

  // Check whether a button has been pressed.
  if (stream_select([STDIN], NULL, NULL) > 0) {
    $pressed = stream_get_contents(STDIN, 1);
    if ($pressed === 'q') {
      $resume = false;
      do {
        echo "Press q again to quit or r to resume: ";
        $pressed = stream_get_contents(STDIN, 1);
        if ($pressed === 'q') {
          $quit = true;
          break;
        } else if ($pressed === 'r') {
          echo "\nResuming.\n";
          break;
        } else {
          echo "\nUnknown input.\n"
        }
      } while(true);
    }
  }
}

// Write last archived message time to file,
// so that archival can be resumed for following messages.
if($last_timestamp > 0)
  file_put_contents($last_fn, $last_timestamp);

