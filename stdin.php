<?php

function readc($stream, $silent=false) {
  readline_callback_handler_install('', function() {});
  $char = stream_get_contents($stream, 1);
  readline_callback_handler_remove();
  if (!$silent)
    echo $char;
  return $char;
}

function can_read($stream) {
  readline_callback_handler_install('', function() {});
  $read = [$stream];
  $empty = [];
  $can_read = stream_select($read, $empty, $empty, 0) > 0;
  readline_callback_handler_remove();
  return $can_read;
}

