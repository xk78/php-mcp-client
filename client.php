<?php

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use GuzzleHttp\Client;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// 初始化环境
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    exec('chcp 65001 > nul'); // Windows设置UTF-8编码
}
mb_internal_encoding('UTF-8');

class McpClient
{
    private $url; // mcp server 地址
    private $mcpSessionId; // 会话ID
    private $log; // 日志实例
    private $http; // 请求实例
    private $openai; // OpenAI 实例
    private $tools = []; // 工具

    public function __construct($url)
    {
        $this->url = $url;

        // 清空日志
        file_put_contents('logger.log', '');
        // 创建日志实例
        $this->log = new Logger('client');
        $this->log->pushHandler(new StreamHandler('logger.log', Level::Info));

        // 创建请求实例
        $this->http = new Client([
            'headers' => [
                'Accept' => 'application/json, text/event-stream',
            ]
        ]);

        // 创建 OpenaAI 实例
        $this->openai = OpenAI::factory()
            ->withApiKey($_ENV['OPENAI_KEY'])
            ->withBaseUri('https://api.deepseek.com/v1')
            ->withHttpHeader('Authorization', 'Bearer ' . $_ENV['OPENAI_KEY'])
            ->withHttpClient(new Client([
                'verify' => false
            ]))
            ->make();

        // 连接 mcp
        $this->connectToMcp();

        // 模型对话初始化
        $this->init();
    }

    // 模型对话
    public function init() {
        while (true) {

            echo "\n请输入文字：";
            $userInput = fgets(STDIN);
            $userInput = trim($userInput);

            // 退出对话
            if ($userInput === 'exit') break;

            $response = $this->openai->chat()->createStreamed([
                'model' => 'deepseek-chat',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => '你是一个专业的技术支持助手，可以调用工具，用中文回答，保持回答简洁专业，这段是系统提示词'
                    ],
                    [
                        'role' => 'user',
                        'content' => $userInput
                    ]
                ],
                'temperature' => 0.7,
                'tools' => $this->tools,
                'tool_choice' => 'auto',
                'stream' => true
            ]);

            $toolCalls = [];
            $currentToolCall = null;
            $assistantContent = '';
            $shouldProcessTools = false;

            // 处理响应数据
            foreach ($response as $chunk) {
                $choice = $chunk->choices[0];
                $delta = $choice->delta;

                // 处理工具调用
                if (!empty($delta->toolCalls)) {
                    $shouldProcessTools = true;

                    foreach ($delta->toolCalls as $toolChunk) {
                        // 如果是新的工具调用（$toolChunk中存在ID，并且$toolCalls没有这个数据）
                        if (!empty($toolChunk->id) && empty($toolCalls[$toolChunk->id])) {
                            $toolCalls[$toolChunk->id] = [
                                'id' => $toolChunk->id,
                                'type' => $toolChunk->type,
                                'function' => [
                                    'name' => $toolChunk->function->name ?? '',
                                    'arguments' => $toolChunk->function->arguments ?? ''
                                ]
                            ];
                            $currentToolCall = $toolChunk->id;
                        }
                        elseif ($currentToolCall && !empty($toolChunk->id)) {
                            // 继续填充现有工具调用的参数
                            $toolCalls[$currentToolCall]['id'] .= $toolChunk->id ?? '';
                        }
                        elseif ($currentToolCall && !empty($toolChunk->type)) {
                            // 继续填充现有工具调用的参数
                            $toolCalls[$currentToolCall]['type'] .= $toolChunk->type ?? '';
                        }
                        elseif ($currentToolCall && !empty($toolChunk->function->name)) {
                            // 继续填充现有工具调用的参数
                            $toolCalls[$currentToolCall]['function']['name'] .= $toolChunk->function->name ?? '';
                        }
                        elseif ($currentToolCall && !empty($toolChunk->function->arguments)) {
                            // 继续填充现有工具调用的参数
                            $toolCalls[$currentToolCall]['function']['arguments'] .= $toolChunk->function->arguments ?? '';
                        }
                    }
                }

                // 处理普通内容
                if (!empty($delta->content)) {
                    $assistantContent .= $delta->content;

                    // 如果没有工具调用或者工具调用已经完成，直接输出内容
                    if (!$shouldProcessTools) {
                        echo $delta->content;
                        flush();
                    }
                }

                // 如果流结束且有工具调用需要处理
                if ($choice->finishReason === 'tool_calls' && $shouldProcessTools) {
                    break;
                }
            }

            foreach ($toolCalls as $toolCall) {
                $response = $this->call($toolCall['function']['name'], json_decode($toolCall['function']['arguments'], true));
                // 3. 创建包含工具结果的流式请求
                $stream = $this->openai->chat()->createStreamed([
                    'model' => 'deepseek-chat',
                    'messages' => [
                        ['role' => 'system', 'content' => '...'],
                        ['role' => 'user', 'content' => $userInput],
                        ['role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall]],
                        [
                            'role' => 'tool',
                            'content' => json_encode($response),
                            'tool_call_id' => $toolCall['id'],
                            'name' => $toolCall['function']['name']
                        ]
                    ],
                    'stream' => true
                ]);

                // 4. 流式输出结果
                foreach ($stream as $chunk) {
                    echo $chunk['choices'][0]['delta']['content'] ?? '';
                    flush();
                }
            }
        }
    }

    // 初始化(与 mcp server 建立连接)
    public function connectToMcp()
    {
        // 发送client基本信息
        $this->log->info('开始建立连接');
        $response = $this->http->post($this->url, [
            'json' => [
                "jsonrpc" => "2.0",
                "id" => 0,
                "method" => "initialize",
                "params" => [
                    "protocolVersion" => "2024-11-05",
                    "capabilities" => (Object)[],  // 注意是对象而非数组
                    "clientInfo" => [
                        "name" => "Cline",
                        "version" => "3.12.3"
                    ],
                ]
            ]
        ]);
        $this->mcpSessionId = $response->getHeader('mcp-session-id')[0]; // 存储会话ID
        $this->log->info('建立连接成功：' . $this->mcpSessionId);
        $this->log->info('响应：' . $response->getBody()->getContents());

        // 添加 mcp-session-id 会话ID 请求头
        $this->http = new Client([
            'headers' => [
                'Accept' => 'application/json, text/event-stream',
                'mcp-session-id' => $this->mcpSessionId,
            ]
        ]);

        // 通知 mcp server 已初始化成功
        $this->log->info('发送已初始化通知');
        $this->http->post($this->url, [
            'json' => [
                'method' => 'notifications/initialized',
                'jsonrpc' => '2.0',
            ]
        ]);

        // 获取工具列表
        $tools = $this->getTools();
        // 格式化
        $formatTools = json_decode(explode('data: ', $tools)[1])->result->tools;
        foreach ($formatTools as $tool) {
            array_push($this->tools, [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => [
                        'type' => $tool->inputSchema->type,
                        'properties' => $tool->inputSchema->properties,
                        'required' => $tool->inputSchema->required
                    ]
                ]
            ]);
            echo "工具名称: " . $tool->name . "\n";
            echo "描述: " . $tool->description . "\n";
            echo "-----------------\n";
        }
    }

    // 获取工具列表
    public function getTools() {
        $response = $this->http->post($this->url, [
            'json' => [
                'method' => 'tools/list',
                "jsonrpc" => "2.0",
                'id' => 1
            ]
        ]);
        return $response->getBody()->getContents();
    }

    // 调用工具
    public function call($name, $args) {
        $response = $this->http->post($this->url, [
            'json' => [
                'method' => 'tools/call',
                'params' => [
                    'name' => $name,
                        'arguments' => $args,
                ],
                'jsonrpc' => '2.0',
                'id' => 1
            ]
        ]);
        return $response->getBody()->getContents();
    }
}

new McpClient('http://localhost:8000/mcp/');