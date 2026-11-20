<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TicketCommentController extends Controller
{
    public function index(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        $comments = $ticket->comments()
            ->with('author')
            ->when(! $request->user()->isTechnician(), fn ($q) => $q->where('is_internal', false))
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(50);

        return CommentResource::collection($comments);
    }

    public function store(StoreCommentRequest $request, Ticket $ticket): JsonResponse
    {
        $comment = $ticket->comments()->make($request->validated());
        $comment->author()->associate($request->user());
        $comment->save();

        return (new CommentResource($comment->load('author')))->response()->setStatusCode(201);
    }
}
