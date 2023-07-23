<?php

namespace Plugin\Reforward;

use App\Api\Controller\Threads\ThreadTrait;
use App\Api\Serializer\CommentPostSerializer;
use App\Common\CacheKey;
use Discuz\Base\DzqCache;
use App\Common\ResponseCode;
use App\Models\Group;
use App\Models\GroupUser;
use App\Models\Post;
use App\Models\Thread;
use App\Models\ThreadTom;
use App\Models\User;
use App\Modules\ThreadTom\TomBaseBusi;
use Illuminate\Support\Arr;

class ReforwardBusi extends TomBaseBusi
{
    use ThreadTrait;

    protected $thread;

    public function select()
    {
        if (!isset($this->body['_plugin'])) {
            $plugin = ['name' => 'reforward'];
            $this->body['_plugin'] = $plugin;
        }

        $result = [];
        $threadIds = $this->getParams('threadIds');
        if (!empty($threadIds)) {
            $threads = DzqCache::hMGet(CacheKey::LIST_THREADS_V3_THREADS, $threadIds, function ($threadIds) {
                return Thread::query()->whereIn('id', $threadIds)->get()->toArray();
            }, 'id');
            $posts = DzqCache::hMGet(CacheKey::LIST_THREADS_V3_POSTS, $threadIds, function ($threadIds) {
                return Post::instance()->getPosts($threadIds);
            }, 'thread_id');
            foreach ($threads as $threadId => $thread) {
                $this->thread = $thread;
                $post = $posts[$threadId];
                if (empty($post)) {
                    array_push($result, [
                        'threadId' => $threadId,
                        'postId' => null,
                        'threadDetail' => null
                    ]);
                    break;
                }
                $data = $this->getThreadDetail($post);
                array_push($result, [
                    'threadId' => $threadId,
                    'postId' => $post['id'],
                    'threadDetail' => $data
                ]);
            }
        }

        $postIds = $this->getParams('postIds');
        if (!empty($postIds)) {
            $coment_post_serialize = $this->app->make(CommentPostSerializer::class);
            foreach ($postIds as $postId) {
                $post = Post::find($postId);
                if (empty($post) || $post->is_first || $post->thread->deleted_at) {
                    array_push($result, [
                        'threadId' => $post->thread->id,
                        'postId' => $postId,
                        'postsDetail' => null
                    ]);
                    break;
                }
                $data = $coment_post_serialize->getDefaultAttributes($post, $this->user);
                $data['user'] = $this->getUserWithGroup($data['userId']);
                array_push($result, [
                    'threadId' => $post->thread->id,
                    'postId' => $postId,
                    'postsDetail' => !Arr::get($post, 'deleted_at') ? $data : null
                ]);
            }
        }

        return $this->jsonReturn($result);
    }

    protected function getUserWithGroup($userId)
    {
        if (!$userId) {
            return null;
        }
        $user = User::query()->where('id', [$userId])->first(['id', 'nickname', 'avatar', 'realname'])->toArray();
        $groups = GroupUser::instance()->getGroupInfo([$userId]);
        $groups = array_column($groups, null, 'user_id');
        $user['groups'] = [];
        if ($groups) {
            $user['groups'] = [
                'id' => $groups[$userId]['group_id'],
                'name' => $groups[$userId]['groups']['name'],
                'isDisplay' => $groups[$userId]['groups']['is_display'],
                'level' => $groups[$userId]['groups']['level']
            ];
        }
        return $user;
    }

    public function create()
    {
        $threadIds = $this->getParams('threadIds');
        $postIds = $this->getParams('postIds');

        // 现在只能转发一个帖子
        if (count($threadIds) + count($postIds) !== 1) {
            $this->outPut(ResponseCode::INVALID_PARAMETER, '只能转发一个帖子');
        }

        foreach ($threadIds as $threadId) {
            $thread = Thread::find($threadId);
            if (empty($thread)) {
                $this->outPut(ResponseCode::RESOURCE_NOT_FOUND, '找不到要转发的帖子');
            }
        }
        foreach ($postIds as $postId) {
            $post = Post::find($postId);
            if (empty($post)) {
                $this->outPut(ResponseCode::RESOURCE_NOT_FOUND, '找不到要转发的内容');
            }
        }

        // 这里的格式暂时保存为数组，方便以后一个帖子多个转发的时候扩展
        $result = [];
        if ($threadIds) {
            $result['threadIds'] = $threadIds;
        }
        if ($postIds) {
            $result['postIds'] = $postIds;
        }
        return $this->jsonReturn($result);
    }

    public function update()
    {
        $tom = ThreadTom::query()->where([
            'thread_id' => $this->threadId,
            'tom_type' => $this->tomId,
            'key' => $this->key,
            'status' => ThreadTom::STATUS_ACTIVE
        ])->first();

        if (empty($tom)) {
            $this->outPut(ResponseCode::RESOURCE_NOT_FOUND);
        }

        $value = json_decode($tom->value, true);
        return $this->jsonReturn($value);
    }

    private function getThreadDetail($post)
    {
        if (empty($post)) {
            return null;
        }

        $thread = $this->thread;
        if (empty($thread)) {
            return null;
        }

        $hasPermission = $this->canViewThreadDetail($this->user, $thread);
        if (!$hasPermission) {
            return null;
        }

        $user = User::query()->where('id', $thread['user_id'])->first();
        if (empty($user)) {
            return null;
        }

        if ($this->user->isGuest()) {
            $loginUserData = [];
        } elseif ($this->user->id == $thread['user_id']) {
            $loginUserData = $user;
        } else {
            $loginUserData = User::query()->where('id', $this->user->id)->first();
        }

        $group = Group::getGroup($user->id);

        $tomInputIndexes = $this->getTomContent($thread);

        return $this->packThreadDetail($user, $group, $thread, $post, $tomInputIndexes['tomContent'], true, $tomInputIndexes['tags'], $loginUserData);
    }

    private function canViewThreadDetail($user, $thread)
    {
        // 审核状态下，作者本人与管理员可见
        if (Arr::get($thread, 'is_approved') == Thread::UNAPPROVED) {
            return $thread['user_id'] == $user->id || $user->isAdmin();
        }

        // 是本人，且（没有删除或者是自己删除的）
        if (
            $thread['user_id'] == $user->id
            && (!Arr::get($thread, 'deleted_at') || Arr::get($thread, 'deleted_user_id') == $user->id)
        ) {
            return true;
        }

        // 查看自己的草稿
        if (Arr::get($thread, 'is_draft')) {
            return $thread['user_id'] == $user->id;
        }

        return true;
    }

    private function getTomContent($thread)
    {
        $threadId = $thread['id'];
        $threadTom = ThreadTom::query()
            ->where([
                'thread_id' => $threadId,
                'status' => ThreadTom::STATUS_ACTIVE
            ])->orderBy('key')->get()->toArray();
        $tomContent = $tags = [];
        foreach ($threadTom as $item) {
            //如果是部分付费的话，将 price_ids 放进 body
            $tom_value = json_decode($item['value'], true);
            $priceIds = json_decode($item['price_ids'], true);
            if ($item['price_type'] && !empty($priceIds)) {
                $tom_value += ['priceIds' => $priceIds];
            }
            $tomContent[$item['key']] = $this->buildTomJson($threadId, $item['tom_type'], $this->SELECT_FUNC, $tom_value);
            $tags[$item['key']]['tag'] = $item['tom_type'];
        }
        return ['tomContent' => $tomContent, 'tags' => $tags];
    }
}
