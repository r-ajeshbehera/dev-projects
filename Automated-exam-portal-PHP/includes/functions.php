<?php
require_once __DIR__ . '/../config/config.php';

function generate_questions($topic, $num_questions = 5) {
    $url = 'https://api.openai.com/v1/completions';
    $data = [
        'model' => 'text-davinci-003',
        'prompt' => "Generate $num_questions multiple-choice questions about $topic. Each question should have 4 options (A, B, C, D) and indicate the correct answer.",
        'max_tokens' => 500
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        die('OpenAI API error: ' . curl_error($ch));
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (!isset($result['choices'][0]['text'])) {
        die('Invalid OpenAI API response');
    }
    return parse_openai_response($result['choices'][0]['text']);
}

function parse_openai_response($text) {
    $questions = [];
    $lines = explode("\n", trim($text));
    $current_question = [];
    
    foreach ($lines as $line) {
        if (preg_match('/^Q\d+: (.+)/', $line, $matches)) {
            if ($current_question) $questions[] = $current_question;
            $current_question = ['text' => $matches[1], 'options' => []];
        } elseif (preg_match('/^[A-D]\. (.+)/', $line, $matches)) {
            $current_question['options'][substr($line, 0, 1)] = $matches[1];
        } elseif (preg_match('/^Correct: ([A-D])/', $line, $matches)) {
            $current_question['correct_answer'] = $matches[1];
        }
    }
    if ($current_question && count($current_question['options']) === 4) {
        $questions[] = $current_question;
    }
    return $questions;
}
?>