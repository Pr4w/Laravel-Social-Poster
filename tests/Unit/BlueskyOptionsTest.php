<?php

use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\Bluesky\BlueskyOptions;

const BSKY_OPTIONS_POST = 'at://did:plc:4vx5zk2wrb7qmaz3rdc6yjfo/app.bsky.feed.post/3m5kqzw7yx22c';

it('is valid with no options and targets Bluesky', function () {
    $options = new BlueskyOptions;

    expect($options->platform())->toBe(Platform::Bluesky)
        ->and($options->validate()->isEmpty())->toBeTrue()
        ->and($options->extra())->toBe([])
        ->and($options->hasGates())->toBeFalse()
        ->and($options->linkPreview)->toBeTrue()
        ->and($options->detectFacets)->toBeTrue();
});

it('accepts a full, valid set of options', function () {
    $options = new BlueskyOptions(
        langs: ['en', 'pt-BR'],
        linkCard: ['uri' => 'https://example.com/a', 'title' => 'A', 'description' => 'D', 'thumb' => '/tmp/a.jpg'],
        mentions: ['alice.bsky.social' => 'did:plc:alice'],
        facets: [['index' => ['byteStart' => 0, 'byteEnd' => 2], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'x']]]],
        tags: ['#launch', 'tools'],
        selfLabels: ['nudity', '!no-unauthenticated'],
        replyControl: ['mention', 'followers', 'at://did:plc:x/app.bsky.graph.list/3abc'],
        disableQuotes: true,
        replyTo: ['uri' => BSKY_OPTIONS_POST, 'cid' => 'bafy', 'root' => ['uri' => BSKY_OPTIONS_POST, 'cid' => 'bafyroot']],
        quote: ['uri' => BSKY_OPTIONS_POST, 'cid' => 'bafy'],
        videoCaptions: ['en' => '/tmp/en.vtt', 'fr-CA' => 'https://cdn.example.com/fr.vtt'],
        videoPresentation: 'gif',
        extra: ['via' => 'x'],
    );

    expect($options->validate()->all())->toBe([])
        ->and($options->hasGates())->toBeTrue()
        ->and($options->extra())->toBe(['via' => 'x']);
});

it('reports each invalid option', function (array $args, string $field) {
    expect((new BlueskyOptions(...$args))->validate()->has($field))->toBeTrue();
})->with([
    'four langs' => [['langs' => ['en', 'fr', 'de', 'es']], 'langs'],
    'bad lang' => [['langs' => ['english!']], 'langs'],
    'nine tags' => [['tags' => array_map(fn ($i) => "t{$i}", range(1, 9))], 'tags'],
    'tag with space' => [['tags' => ['two words']], 'tags'],
    'empty tag' => [['tags' => ['#']], 'tags'],
    'long tag' => [['tags' => [str_repeat('a', 65)]], 'tags'],
    'unknown label' => [['selfLabels' => ['spoiler']], 'selfLabels'],
    'mention without did' => [['mentions' => ['alice.bsky.social' => 'alice']], 'mentions'],
    'mention list' => [['mentions' => ['did:plc:x']], 'mentions'],
    'bad facet' => [['facets' => [['index' => ['byteStart' => 3, 'byteEnd' => 1], 'features' => [['x' => 1]]]]], 'facets.0'],
    'facet without features' => [['facets' => [['index' => ['byteStart' => 0, 'byteEnd' => 1]]]], 'facets.0'],
    'six reply rules' => [['replyControl' => ['mention', 'following', 'followers', 'mention', 'following', 'followers']], 'replyControl'],
    'unknown reply rule' => [['replyControl' => ['everyone']], 'replyControl'],
    'reply to a profile' => [['replyTo' => ['uri' => 'at://did:plc:x/app.bsky.actor.profile/self', 'cid' => 'b']], 'replyTo'],
    'reply without cid' => [['replyTo' => ['uri' => BSKY_OPTIONS_POST]], 'replyTo'],
    'bad root' => [['replyTo' => ['uri' => BSKY_OPTIONS_POST, 'cid' => 'b', 'root' => ['uri' => 'https://bsky.app/x', 'cid' => 'c']]], 'replyTo.root'],
    'quote by url' => [['quote' => ['uri' => 'https://bsky.app/profile/x/post/y', 'cid' => 'c']], 'quote'],
    'http link card' => [['linkCard' => ['uri' => 'http://example.com']], 'linkCard'],
    'link card without uri' => [['linkCard' => ['title' => 'x']], 'linkCard'],
    'non-string card title' => [['linkCard' => ['uri' => 'https://example.com', 'title' => ['x']]], 'linkCard'],
    '21 captions' => [['videoCaptions' => array_combine(array_map(fn ($i) => 'l'.chr(97 + $i % 26).chr(97 + intdiv($i, 26)), range(0, 20)), array_fill(0, 21, '/tmp/x.vtt'))], 'videoCaptions'],
    'caption without file' => [['videoCaptions' => ['en' => '']], 'videoCaptions'],
    'caption bad lang' => [['videoCaptions' => ['english' => '/tmp/x.vtt']], 'videoCaptions'],
    'presentation' => [['videoPresentation' => 'loop'], 'videoPresentation'],
]);

it('normalises mentions, tags and reply rules', function () {
    $options = new BlueskyOptions(mentions: ['@Alice.Bsky.Social' => 'did:plc:a', 'bob' => 'nope'], tags: ['#a', 'b', '＃a', ' ', 'a']);

    expect($options->mentionMap())->toBe(['alice.bsky.social' => 'did:plc:a'])
        ->and($options->normalisedTags())->toBe(['a', 'b'])
        ->and(BlueskyOptions::normaliseRule('Mentions'))->toBe('mention')
        ->and(BlueskyOptions::normaliseRule('follower'))->toBe('followers')
        ->and(BlueskyOptions::normaliseRule('following'))->toBe('following')
        ->and(BlueskyOptions::normaliseRule(' at://list '))->toBe('at://list')
        ->and((new BlueskyOptions(replyControl: []))->hasGates())->toBeTrue();
});
