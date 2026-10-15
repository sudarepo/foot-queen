<?php

namespace App\Mcp\Tools\Cams;

use App\Mcp\Tools\AdminTool;
use App\Models\Cam;
use App\Models\CamClickEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get everything stored for one performer — room, profile, bio, photo sets — and their outbound click count.')]
#[IsReadOnly]
class GetCam extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['cam' => ['required']]);

        $cam = Cam::query()
            ->where('username', (string) $validated['cam'])
            ->when(ctype_digit((string) $validated['cam']), fn ($query) => $query->orWhere('id', (int) $validated['cam']))
            ->first();

        if ($cam === null) {
            return Response::error("No cam matches \"{$validated['cam']}\".");
        }

        return Response::json([
            ...$cam->attributesToArray(),
            'outbound_clicks' => CamClickEvent::query()->where('cam_id', $cam->id)->count(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'cam' => $schema->string()->description('Cam id or username.')->required(),
        ];
    }
}
