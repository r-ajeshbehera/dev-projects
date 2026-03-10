<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define base templates and variations
$mcq_base_templates = [
    "What is the primary purpose of {topic} in {context}?",
    "Which {attribute} is most associated with {topic}?",
    "What is a common {entity} used in {topic}?",
    "Which of these is a key feature of {topic}?",
    "Who typically uses {topic} in {context}?",
    "What is a popular {tool} associated with {topic}?",
    "Which {algorithm} is commonly used in {topic}?",
    "What does {topic} primarily help to achieve in {context}?",
    "Which of these is a benefit of using {topic}?",
    "What is a limitation of {topic} in {context}?"
];

$text_base_templates = [
    "What is {topic} and how does it work in {context}?",
    "Explain the significance of {topic} in {context}.",
    "Describe a real-world application of {topic}.",
    "How has {topic} evolved in {context}?",
    "What are the main challenges associated with {topic}?",
    "How does {topic} improve {attribute} in {context}?",
    "What role does {topic} play in {context}?",
    "Explain the basic principles behind {topic}.",
    "How is {topic} implemented in {context}?",
    "What are the advantages of using {topic} in {context}?"
];

// Variations for placeholders
$contexts = ["Computer Science", "software development", "system design", "networking", "data management", "algorithm design", "hardware optimization"];
$attributes = ["programming paradigm", "feature", "benefit", "principle", "concept", "technique", "component"];
$entities = ["data structure", "algorithm", "tool", "library", "framework", "protocol", "interface"];
$tools = ["tool", "language", "compiler", "IDE", "framework", "library"];
$algorithms = ["algorithm", "sorting method", "search technique", "optimization strategy"];

// Generic options for MCQs
$mcq_options_sets = [
    ["To optimize performance", "To design user interfaces", "To cook food", "To play music"],
    ["Object-Oriented", "Procedural", "Functional", "Declarative"],
    ["Array", "Graph", "Queue", "Stack"],
    ["Scalability", "Bright colors", "Sound effects", "Physical size"],
    ["Software Engineers", "Graphic Designers", "Marketing Staff", "HR Managers"],
    ["Python", "Photoshop", "Excel", "Word"],
    ["Dijkstra’s Algorithm", "Bubble Sort", "Quick Sort", "Binary Search"],
    ["Efficiency", "Aesthetics", "Noise reduction", "Taste enhancement"],
    ["Faster execution", "Better graphics", "Louder sound", "Simpler packaging"],
    ["Complexity", "Ease of use", "Free availability", "Bright display"]
];

// Function to generate templates
function generateTemplates($total, $base_templates, $contexts, $attributes, $entities, $tools, $algorithms, $options_sets = null) {
    $templates = [];
    $base_count = count($base_templates);
    $context_count = count($contexts);
    $attribute_count = count($attributes);
    $entity_count = count($entities);
    $tool_count = count($tools);
    $algorithm_count = count($algorithms);
    $options_count = $options_sets ? count($options_sets) : 1;

    for ($i = 0; $i < $total; $i++) {
        $base = $base_templates[$i % $base_count];
        $context = $contexts[$i % $context_count];
        $attribute = $attributes[$i % $attribute_count];
        $entity = $entities[$i % $entity_count];
        $tool = $tools[$i % $tool_count];
        $algorithm = $algorithms[$i % $algorithm_count];

        $question = str_replace(
            ["{context}", "{attribute}", "{entity}", "{tool}", "{algorithm}"],
            [$context, $attribute, $entity, $tool, $algorithm],
            $base
        );

        if ($options_sets) {
            $options = $options_sets[$i % $options_count];
            $correct = rand(0, 3); // Random correct answer
            $templates[] = [
                "question" => $question,
                "options" => $options,
                "correct" => $correct
            ];
        } else {
            $templates[] = ["question" => $question];
        }
    }
    return $templates;
}

// Generate 500,000 MCQs and 500,000 Text questions
$mcq_templates = generateTemplates(500000, $mcq_base_templates, $contexts, $attributes, $entities, $tools, $algorithms, $mcq_options_sets);
$text_templates = generateTemplates(500000, $text_base_templates, $contexts, $attributes, $entities, $tools, $algorithms);

// Combine into final structure
$data = [
    "templates" => [
        "mcq" => $mcq_templates,
        "text" => $text_templates
    ]
];

// Save to JSON file
$json = json_encode($data, JSON_PRETTY_PRINT);
file_put_contents(__DIR__ . '/teacher/question_templates.json', $json);

echo "Generated 1,000,000 templates and saved to teacher/question_templates.json";
?>