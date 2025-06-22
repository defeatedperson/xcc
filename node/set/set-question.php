<?php
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

header('Content-Type: application/json; charset=utf-8');

// 仅允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// CSRF校验
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

$questionsFile = __DIR__ . '/questions.json';
$action = $_POST['action'] ?? 'get';

// 读取题库
if ($action === 'get') {
    if (!file_exists($questionsFile)) {
        echo json_encode(['success' => false, 'message' => '题库文件不存在']);
        exit;
    }
    $json = file_get_contents($questionsFile);
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => '题库文件格式错误']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// 写入题库
if ($action === 'set') {
    $raw = $_POST['questions'] ?? '';
    if (!$raw) {
        echo json_encode(['success' => false, 'message' => '缺少questions参数']);
        exit;
    }
    // 支持直接传json字符串或数组
    $questions = is_array($raw) ? $raw : json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($questions)) {
        echo json_encode(['success' => false, 'message' => 'questions参数格式错误']);
        exit;
    }

    // 题目数量限制
    $count = count($questions);
    if ($count < 3 || $count > 12) {
        echo json_encode(['success' => false, 'message' => '题目数量必须在3~12道之间']);
        exit;
    }

    // 校验格式
    foreach ($questions as $idx => $q) {
        if (
            !isset($q['title']['zh'], $q['title']['en'], $q['title']['ja']) ||
            !isset($q['options']) || !is_array($q['options']) || count($q['options']) < 2 ||
            !isset($q['answer']) || !is_int($q['answer']) ||
            $q['answer'] < 1 || $q['answer'] > count($q['options'])
        ) {
            echo json_encode(['success' => false, 'message' => "第" . ($idx+1) . "题格式不合法"]);
            exit;
        }
        foreach ($q['options'] as $opt) {
            if (!isset($opt['zh'], $opt['en'], $opt['ja'])) {
                echo json_encode(['success' => false, 'message' => "第" . ($idx+1) . "题选项格式不合法"]);
                exit;
            }
        }
    }

    // 写入文件
    if (file_put_contents($questionsFile, json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        echo json_encode(['success' => false, 'message' => '题库写入失败']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => '题库保存成功']);
    exit;
}

// 未知操作
echo json_encode(['success' => false, 'message' => '未知操作']);
exit;
?>