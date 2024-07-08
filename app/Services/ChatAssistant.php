<?php

namespace App\Services;

use App\Integrations\Ollama\OllamaConnector;
use App\Integrations\Claude\ClaudeAIConnector;
use App\Integrations\OpenAI\OpenAIConnector;
use App\Integrations\OpenAI\Requests\ChatRequest as OpenAIChatRequest;
use App\Integrations\Ollama\Requests\ChatRequest as OllamaChatRequest;
use App\Integrations\Claude\Requests\ChatRequest as ClaudeChatRequest;
use App\Models\Assistant;
use App\Models\Project;
use App\Tools\ExecuteCommand;
use App\Tools\ListFiles;
use App\Tools\ReadFile;
use App\Tools\UpdateFile;
use App\Tools\WriteToFile;
use App\Traits\HasTools;
use Exception;
use ReflectionException;
use function Laravel\Prompts\form;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Termwind\render;

class ChatAssistant
{
    use HasTools;

    /**
     * @throws ReflectionException
     */
    public function __construct()
    {
        $this->register([
            ExecuteCommand::class,
            WriteToFile::class,
            UpdateFile::class,
            ListFiles::class,
            ReadFile::class,
        ]);
    }

    public function getCurrentProject(): Project
    {
        $projectPath = getcwd();
        $project = Project::where('path', $projectPath)->first();

        if (! $project) {
            $userChoice = select(
                label: 'No existing project found. Would you like to create a new assistant or use an existing one?',
                options: [
                    'create_new' => 'Create New Assistant',
                    'use_existing' => 'Use Existing Assistant',
                ]
            );

            switch ($userChoice) {
                case 'create_new':
                    $assistantId = $this->createNewAssistant()->id;
                    break;
                case 'use_existing':
                    $assistants = Assistant::all();
                    if ($assistants->isEmpty()) {
                        $assistantId = $this->createNewAssistant()->id;
                    } else {
                        $options = $assistants->pluck('name', 'id')->toArray();
                        $assistantId = select(label: 'Select an assistant', options: $options);
                    }
                    break;
                default:
                    throw new Exception('Invalid choice');
            }

            $project = Project::create([
                'path' => $projectPath,
                'assistant_id' => $assistantId,
            ]);
        }

        return $project;
    }

    public function createNewAssistant()
    {
        $path = getcwd();
        $folderName = basename($path);

        $service = select(
            label: 'Choose the Service for the assistant',
            options: ['openai' => 'OpenAI', 'claude' => 'Claude'],
            default: 'openai' // Default service is openai
        );

        $models = [];
        $servicesConfig = config('aiproviders');
        if (array_key_exists($service, $servicesConfig)) {
            $models = $servicesConfig[$service]['models'];
        }

        $assistant = form()
            ->text(label: 'What is the name of the assistant?', default: ucfirst($folderName . ' Project'), required: true, name: 'name')
            ->text(label: 'What is the description of the assistant? (optional)', name: 'description')
            ->select(
                label: 'Choose the Model for the assistant',
                options: array_combine($models, $models),
                default: reset($models),
                name: 'model'
            )
            ->textarea(
                label: 'Customize the prompt for the assistant?',
                default: config('droid.default_prompt') ?? '',
                required: true,
                hint: 'Include any project details that the assistant should know about.',
                rows: 20,
                name: 'prompt'
            )
            ->submit();

        return Assistant::create([
            'name' => $assistant['name'],
            'description' => $assistant['description'],
            'model' => $assistant['model'],
            'prompt' => $assistant['prompt'],
            'service' => $service,
        ]);
    }

    /**
     * @throws Exception
     */
    public function createThread()
    {
        $project = $this->getCurrentProject();
        $latestThread = $project->threads()->latest()->first();

        if ($latestThread) {
            $threadChoice = select(
                label: 'Found Existing thread, do you want to continue the conversation or start new?',
                options: [
                    'use_existing' => 'Continue',
                    'create_new' => 'Start New Thread',
                ]
            );
            if ($threadChoice === 'use_existing') {
                return $latestThread;
            }
        }

        $threadTitle = 'New Thread';

        $thread = spin(
            fn () => $project->threads()->create([
                'assistant_id' => $project->assistant_id,
                'title' => $threadTitle,
            ]),
            'Creating New Thread...'
        );

        note('🤖: How can I help you?');
        return $thread;
    }

    public function getAnswer($thread, $message): string
    {
        if ($message !== null) {
            $thread->messages()->create([
                'role' => 'user',
                'content' => $message,
            ]);
        }

        $thread->load('messages');

        if ($thread->assistant->service === 'claude') {
            $connector = new ClaudeAIConnector();
            $chatRequest = new ClaudeChatRequest($thread, $this->registered_tools);
        }

        else if ($thread->assistant->service === 'ollama') {
            $connector = new OllamaConnector();
            $chatRequest = new OllamaChatRequest($thread, $this->registered_tools);
        }
        else {
            $connector = new OpenAIConnector();
            $chatRequest = new OpenAIChatRequest($thread, $this->registered_tools);
        }

        $response = spin(fn () => $connector->send($chatRequest)->json(), 'Getting response from '.$thread->assistant->service);

        $choice = $response['choices'][0];

        return $this->handleTools($thread, $choice);
    }

    public function handleTools($thread, $choice): string
    {
        $answer = $choice['message']['content'];

        $thread->messages()->create($choice['message']);

        if ($choice['finish_reason'] === 'tool_calls') {

            foreach ($choice['message']['tool_calls'] as $toolCall) {
                try {
                    $toolResponse = $this->call($toolCall['function']['name'], json_decode($toolCall['function']['arguments'], true));

                    $thread->messages()->create([
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $toolCall['function']['name'],
                        'content' => $toolResponse,
                    ]);

                } catch (Exception $e) {
                    throw new Exception('Error calling tool: ' . $e->getMessage());
                }
            }

            return $this->getAnswer($thread, null);
        }

        render(view('assistant', ['answer' => $answer]));

        return $answer;
    }

}
