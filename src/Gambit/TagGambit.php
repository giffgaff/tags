<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Flarum\Tags\Gambit;

use Flarum\Search\AbstractRegexGambit;
use Flarum\Search\AbstractSearch;
use Flarum\Tags\TagRepository;
use Illuminate\Database\Query\Builder;

class TagGambit extends AbstractRegexGambit
{
    /**
     * {@inheritdoc}
     */
    protected $pattern = 'tag:(.+)';

    /**
     * @var TagRepository
     */
    protected $tags;

    /**
     * @param TagRepository $tags
     */
    public function __construct(TagRepository $tags)
    {
        $this->tags = $tags;
    }

    /**
     * {@inheritdoc}
     */
    protected function conditions(AbstractSearch $search, array $matches, $negate)
    {
        $actor = $search->getActor();
        $slugs = explode(',', trim($matches[1], '"'));

        $tagsToPartiallyLoad = ['general-discussion', 'help-and-support'];
        if (isset($slugs[0]) && in_array($slugs[0], $tagsToPartiallyLoad)) {
            $search->getQuery()->whereRaw('flarum_discussions.last_posted_at > date_sub(now(), interval 1 year)');
        }

        $discussionsWithHiddenSubChildTags = $this->getDiscussionIdsWithHiddenSubscriptionChildTagsQry($actor->id, $slugs);
        $search->getQuery()->whereNotIn('discussions.id', $discussionsWithHiddenSubChildTags);

        $search->getQuery()->where(function (Builder $query) use ($slugs, $negate) {
            foreach ($slugs as $slug) {
                if ($slug === 'untagged') {
                    $query->whereIn('discussions.id', function (Builder $query) {
                        $query->select('discussion_id')
                            ->from('discussion_tag');
                    }, 'or', ! $negate);
                } else {
                    $id = $this->tags->getIdForSlug($slug);

                    $query->whereIn('discussions.id', function (Builder $query) use ($id) {
                        $query->select('discussion_id')
                            ->from('discussion_tag')
                            ->where('tag_id', $id);
                    }, 'or', $negate);
                }
            }
        });
    }

    /**
     * Returns a single column query with all discussion IDs which belongs to a child tag the
     * user has hidden.
     *
     * @param int $userId
     * @param array|null $excludeTags
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function getDiscussionIdsWithHiddenSubscriptionChildTagsQry(int $userId, array $excludeTags = null)
    {
        $query = $this->tags->query();
        $query->select('discussion_tag.discussion_id')
            ->from('discussion_tag')
            ->join('tag_user', 'tag_user.tag_id', '=', 'discussion_tag.tag_id')
            ->join('tags', 'tag_user.tag_id', '=', 'tags.id')
            ->where('tag_user.user_id', '=', $userId)
            ->where('tag_user.subscription', 'hide')
            ->whereNotNull("tags.parent_id");

        if ($excludeTags !== null) {
            $query->whereNotIn('tags.slug', $excludeTags);
        }

        return $query;
    }
}
