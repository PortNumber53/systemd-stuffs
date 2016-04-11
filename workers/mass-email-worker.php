<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

use Monolog\Logger as Logger;
use Monolog\Formatter\JsonFormatter as JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\PHPConsoleHandler as PHPConsoleHandler;

$settings = parse_ini_file(__DIR__ . '/.settings', true);
print_r($settings);
$connection = new AMQPStreamConnection($settings['server']['HOST'], $settings['server']['PORT'], $settings['server']['USER'], $settings['server']['PASSWORD']);

$channel = $connection->channel();

if (isset($argv[1])) {
    $environment = filter_var($argv[1], FILTER_SANITIZE_STRING);
} else {
    $environment = 'development';
}

$log = new Logger($argv[0]);
$log_handler = new StreamHandler('php://stdout', Logger::INFO);
$log_handler->setFormatter(new JsonFormatter);
$log->pushHandler($log_handler);
$log->addInfo('Waiting for messages.');

$callback = function ($msg) {
    global $environment, $settings, $log, $channel;

    $log->addDebug('Message received', json_decode($msg->body, true));

    $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);

    $subject = 'RabbitMQ worker ' . date('Y-m-d H:i:s');
    $message = $msg->body;

    $json = json_decode($msg->body, true);
    $recipients = $json['recipients'];

    $from    = $json['from'];
    //$mail_to = $json['mail_to'];
    $subject = $json['subject'];
    $body_text = $json['body_text'];
    $body_html = isset($json['body_html']) ? $json['body_html'] : $json['body_text'];

    $messages_to_queue = array();
    foreach ($recipients as $recipient) {
        $variables = json_decode($recipient['variables'], true);

        $original_mail_to = $mail_to = $recipient['email'];
        $log->addInfo('Processing message', array(
            'mail_to' => $original_mail_to,
            ));
        if ($environment == 'development') {
                $mail_to = 'mauriciootta+cybird@gmail.com';
        }

        $each_body_text = $body_text;
        $each_body_html = $body_html;
        if (is_array($variables)) {
            foreach ($variables as $key => $value) {
                $each_body_text = str_replace('%' . $key . '%', $value, $each_body_text);
                $each_body_html = str_replace('%' . $key . '%', $value, $each_body_html);
            }
        }

        $message_data = array(
            'from' => array('contact@soccergamemanager.com' => 'SGM Postmaster'),
            'mail_to' => $mail_to,
            'subject' => $subject,
            'body' => $each_body_text,
            'html' => $each_body_html,
        );

        // Publish all messages
        $msg = new AMQPMessage(
            json_encode($message_data),
            array('delivery_mode' => 2) # make message persistent
        );
        $channel->queue_declare($environment . ':' . $settings['queue']['INDIVIDUAL'], false, true, false, false);
        $channel->basic_publish($msg, '', $environment . ':' . $settings['queue']['INDIVIDUAL']);
        $log->addInfo('Processing message', array(
            'mail_to' => $original_mail_to,
            ));
    }
};

$channel->basic_qos(null, 1, null);
$channel->basic_consume($environment . ':' . $settings['queue']['MASS'], '', false, false, false, false, $callback);

while (count($channel->callbacks)) {
    $channel->wait();
}

$channel->close();
$connection->close();
