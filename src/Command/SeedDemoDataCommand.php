<?php

namespace App\Command;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Localization\DemoTranslations;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-demo-data', description: 'Add the sample creators, companies, and campaigns.')]
final class SeedDemoDataCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $creators = [
            new Creator('maya-chen', 'Maya Chen', 'Travel', 'Los Angeles, CA', 'I look for quiet places and unhurried ways to experience them—from coastal mornings and thoughtful stays to routes that leave room to wander. My guides pair practical travel notes with warm, story-led photography, so a trip feels possible rather than out of reach.', [
                ['platform' => 'Instagram', 'handle' => '@mayagoesplaces', 'followers' => 84200, 'lastUpdated' => '2026-09-18'],
                ['platform' => 'TikTok', 'handle' => '@mayagoesplaces', 'followers' => 128000, 'lastUpdated' => '2026-09-18'],
            ], ['Slow travel', 'Outdoor', 'Photography'], 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=720&q=85', DemoTranslations::creator('maya-chen'),
                tagline: 'Small places, slower stories.',
                portfolio: [
                    ['id' => 'maya-item-1', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1000&q=85', 'title' => 'A quieter coastline', 'platform' => 'Instagram'],
                    ['id' => 'maya-item-2', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1464822759023-fed622ff2c3b?auto=format&fit=crop&w=1000&q=85', 'title' => 'Mountain mornings', 'platform' => 'Instagram'],
                    ['id' => 'maya-item-3', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1470770841072-f978cf4d019e?auto=format&fit=crop&w=1000&q=85', 'title' => 'A weekend away', 'platform' => 'TikTok'],
                    ['id' => 'maya-item-4', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1501785888041-af3ef285b470?auto=format&fit=crop&w=1000&q=85', 'title' => 'A lakeside pause', 'platform' => 'Instagram'],
                ],
                packages: [
                    ['id' => 'maya-travel-story', 'copyKey' => 'instagram-story', 'platform' => 'Instagram', 'title' => 'Instagram story set', 'description' => 'Three thoughtful story frames introduce the product in a natural moment, with a brand tag and a short message that fits my usual content.', 'price' => 450],
                    ['id' => 'maya-travel-carousel', 'copyKey' => 'instagram-carousel', 'platform' => 'Instagram', 'title' => 'Instagram photo carousel', 'description' => 'A carousel of original photos and short captions walks my audience through the product, its details, and my personal take.', 'price' => 680],
                    ['id' => 'maya-youtube-feature', 'copyKey' => 'youtube-video', 'platform' => 'YouTube', 'title' => 'YouTube video feature', 'description' => 'A YouTube integration includes a clear product introduction, my honest experience, and links in the video description.', 'price' => null],
                ],
                categories: ['Travel', 'Lifestyle'],
                faqs: [
                    ['question' => 'Do you travel for collaborations?', 'answer' => 'Yes, depending on timing and travel arrangements. Send a request with the location and dates.'],
                    ['question' => 'Can I request a custom package?', 'answer' => 'Absolutely. Share your idea and preferred deliverables in the request message.'],
                ],
            ),
            new Creator('jordan-rivera', 'Jordan Rivera', 'Food', 'Brooklyn, NY', 'I cook for real life: seasonal ingredients, simple recipes, and a table that brings people together. I share dependable steps, small hosting tips, and ideas that turn an ordinary weeknight dinner into a good reason to slow down and spend time together.', [
                ['platform' => 'Instagram', 'handle' => '@jordanplates', 'followers' => 56300, 'lastUpdated' => '2026-09-22'],
                ['platform' => 'YouTube', 'handle' => '@jordanplates', 'followers' => 31900, 'lastUpdated' => '2026-09-22'],
            ], ['Recipes', 'Hosting', 'Home cooking'], 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=720&q=85', DemoTranslations::creator('jordan-rivera'),
                tagline: 'Good food, made for sharing.',
                portfolio: [
                    ['id' => 'jordan-item-1', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1490645935967-10de6ba17061?auto=format&fit=crop&w=1000&q=85', 'title' => 'A table for friends', 'platform' => 'Instagram'],
                    ['id' => 'jordan-item-2', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=1000&q=85', 'title' => 'Weeknight greens', 'platform' => 'Instagram'],
                    ['id' => 'jordan-item-3', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1476224203421-9ac39bcb3327?auto=format&fit=crop&w=1000&q=85', 'title' => 'A recipe to keep', 'platform' => 'YouTube'],
                    ['id' => 'jordan-item-4', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=1000&q=85', 'title' => 'Fresh from the market', 'platform' => 'Instagram'],
                ],
                packages: [
                    ['id' => 'jordan-recipe-video', 'copyKey' => 'tiktok-video', 'platform' => 'TikTok', 'title' => 'TikTok short video', 'description' => 'A lively video opens with a clear hook and connects the product to a story that feels natural to my content.', 'price' => null],
                    ['id' => 'jordan-table-stories', 'copyKey' => 'instagram-story', 'platform' => 'Instagram', 'title' => 'Instagram story set', 'description' => 'Three thoughtful story frames introduce the product in a natural moment, with a brand tag and a short message that fits my usual content.', 'price' => 320],
                    ['id' => 'jordan-youtube-feature', 'copyKey' => 'youtube-video', 'platform' => 'YouTube', 'title' => 'YouTube video feature', 'description' => 'A YouTube integration includes a clear product introduction, my honest experience, and links in the video description.', 'price' => 750],
                ],
                categories: ['Food', 'Lifestyle'],
                faqs: [
                    ['question' => 'Can you create a recipe around a product?', 'answer' => 'Yes. I like to build recipes that make sense for the product and my audience.'],
                    ['question' => 'Do you offer YouTube content?', 'answer' => 'Yes, YouTube integrations can be discussed as part of a custom request.'],
                ],
            ),
            new Creator('amara-okafor', 'Amara Okafor', 'Wellness', 'London, UK', 'I believe in approachable movement and small moments of mindfulness that support real life, not a perfect picture. I share gentle workouts, ideas for rest, and products that fit naturally into an everyday routine. My goal is to make wellness feel welcoming, whatever your experience level.', [
                ['platform' => 'Instagram', 'handle' => '@amarainmotion', 'followers' => 71600, 'lastUpdated' => '2026-09-20'],
                ['platform' => 'TikTok', 'handle' => '@amarainmotion', 'followers' => 93600, 'lastUpdated' => '2026-09-20'],
            ], ['Movement', 'Mindfulness', 'Wellness'], 'https://images.unsplash.com/photo-1531123897727-8f129e1688ce?auto=format&fit=crop&w=720&q=85', DemoTranslations::creator('amara-okafor'),
                tagline: 'Everyday movement, with joy.',
                portfolio: [
                    ['id' => 'amara-item-1', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1518611012118-696072aa579a?auto=format&fit=crop&w=1000&q=85', 'title' => 'A moment to move', 'platform' => 'Instagram'],
                    ['id' => 'amara-item-2', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?auto=format&fit=crop&w=1000&q=85', 'title' => 'A little breathing room', 'platform' => 'TikTok'],
                    ['id' => 'amara-item-3', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1506126613408-eca07ce68773?auto=format&fit=crop&w=1000&q=85', 'title' => 'A gentle reset', 'platform' => 'Instagram'],
                    ['id' => 'amara-item-4', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1575052814086-f385e2e2ad1b?auto=format&fit=crop&w=1000&q=85', 'title' => 'Finding a steady rhythm', 'platform' => 'Instagram'],
                ],
                packages: [
                    ['id' => 'amara-wellness-video', 'copyKey' => 'tiktok-video', 'platform' => 'TikTok', 'title' => 'TikTok short video', 'description' => 'A lively video opens with a clear hook and connects the product to a story that feels natural to my content.', 'price' => 380],
                    ['id' => 'amara-routine-reel', 'copyKey' => 'instagram-reel', 'platform' => 'Instagram', 'title' => 'Instagram Reel', 'description' => 'An original short video connects the product to a small everyday story, with a clear creative focus and brand tag.', 'price' => 520],
                    ['id' => 'amara-reset-stories', 'copyKey' => 'instagram-story', 'platform' => 'Instagram', 'title' => 'Instagram story set', 'description' => 'Three thoughtful story frames introduce the product in a natural moment, with a brand tag and a short message that fits my usual content.', 'price' => 280],
                ],
                categories: ['Wellness', 'Lifestyle'],
                faqs: [
                    ['question' => 'What kind of wellness work do you take on?', 'answer' => 'I focus on approachable movement, mindful routines, and products that fit real life.'],
                    ['question' => 'Can you share a custom concept first?', 'answer' => 'Yes. Include your campaign goals and I can discuss a suitable creative direction.'],
                ],
            ),
            new Creator('leo-martin', 'Leo Martin', 'Lifestyle', 'Austin, TX', 'I make home feel thoughtful, comfortable, and full of things with a story. I share how I refresh second-hand finds, repair what I already own, and make small changes without a big budget. I believe a home should grow with us instead of chasing every passing trend.', [
                ['platform' => 'Instagram', 'handle' => '@leomakesroom', 'followers' => 48200, 'lastUpdated' => '2026-09-12'],
                ['platform' => 'Pinterest', 'handle' => '@leomakesroom', 'followers' => 25100, 'lastUpdated' => '2026-09-12'],
            ], ['Interiors', 'DIY', 'Sustainability'], 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?auto=format&fit=crop&w=720&q=85', DemoTranslations::creator('leo-martin'),
                tagline: 'A more considered kind of home.',
                portfolio: [
                    ['id' => 'leo-item-1', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1616486338812-3dadae4b4ace?auto=format&fit=crop&w=1000&q=85', 'title' => 'Room to slow down', 'platform' => 'Instagram'],
                    ['id' => 'leo-item-2', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1618221195710-dd6b41faaea6?auto=format&fit=crop&w=1000&q=85', 'title' => 'A small home refresh', 'platform' => 'Instagram'],
                    ['id' => 'leo-item-3', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1615874694520-474822394e73?auto=format&fit=crop&w=1000&q=85', 'title' => 'Found and made', 'platform' => 'TikTok'],
                    ['id' => 'leo-item-4', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?auto=format&fit=crop&w=1000&q=85', 'title' => 'A home with a story', 'platform' => 'Instagram'],
                ],
                packages: [
                    ['id' => 'leo-home-feature', 'copyKey' => 'instagram-carousel', 'platform' => 'Instagram', 'title' => 'Instagram photo carousel', 'description' => 'A carousel of original photos and short captions walks my audience through the product, its details, and my personal take.', 'price' => null],
                    ['id' => 'leo-diy-video', 'copyKey' => 'instagram-reel', 'platform' => 'Instagram', 'title' => 'Instagram Reel', 'description' => 'An original short video connects the product to a small everyday story, with a clear creative focus and brand tag.', 'price' => 480],
                    ['id' => 'leo-project-stories', 'copyKey' => 'instagram-story', 'platform' => 'Instagram', 'title' => 'Instagram story set', 'description' => 'Three thoughtful story frames introduce the product in a natural moment, with a brand tag and a short message that fits my usual content.', 'price' => 240],
                ],
                categories: ['Lifestyle', 'Travel'],
                faqs: [
                    ['question' => 'Do you work with sustainable brands?', 'answer' => 'Yes, when the product and its materials align with a more thoughtful home.'],
                    ['question' => 'Can a package include a room makeover?', 'answer' => 'Custom scopes are welcome. Please share the space, timeline, and brief.'],
                ],
            ),
            new Creator('sana-kim', 'Sana Kim', 'Beauty', 'Seattle, WA', 'Skincare should not feel like pressure to build a perfect or complicated routine. I share honest notes, simple steps, and products I have actually tried, with a special focus on sensitive skin. Every recommendation should be useful, clear, and realistic enough for everyday life.', [
                ['platform' => 'Instagram', 'handle' => '@sanaskinnotes', 'followers' => 104000, 'lastUpdated' => '2026-09-25'],
                ['platform' => 'TikTok', 'handle' => '@sanaskinnotes', 'followers' => 167000, 'lastUpdated' => '2026-09-25'],
            ], ['Skincare', 'Beauty', 'Sensitive skin'], 'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?auto=format&fit=crop&w=720&q=85', DemoTranslations::creator('sana-kim'),
                tagline: 'Skincare that leaves room for life.',
                portfolio: [
                    ['id' => 'sana-item-1', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?auto=format&fit=crop&w=1000&q=85', 'title' => 'A thoughtful routine', 'platform' => 'Instagram'],
                    ['id' => 'sana-item-2', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1570172619644-dfd03ed5d881?auto=format&fit=crop&w=1000&q=85', 'title' => 'A quiet skincare moment', 'platform' => 'TikTok'],
                    ['id' => 'sana-item-3', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1556228720-195a672e8a03?auto=format&fit=crop&w=1000&q=85', 'title' => 'Honest product notes', 'platform' => 'Instagram'],
                    ['id' => 'sana-item-4', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1598440947619-2c35fc9aa908?auto=format&fit=crop&w=1000&q=85', 'title' => 'A routine that feels like mine', 'platform' => 'Instagram'],
                ],
                packages: [
                    ['id' => 'sana-skincare-story', 'copyKey' => 'instagram-story', 'platform' => 'Instagram', 'title' => 'Instagram story set', 'description' => 'Three thoughtful story frames introduce the product in a natural moment, with a brand tag and a short message that fits my usual content.', 'price' => 520],
                    ['id' => 'sana-routine-reel', 'copyKey' => 'instagram-reel', 'platform' => 'Instagram', 'title' => 'Instagram Reel', 'description' => 'An original short video connects the product to a small everyday story, with a clear creative focus and brand tag.', 'price' => 690],
                    ['id' => 'sana-product-video', 'copyKey' => 'tiktok-video', 'platform' => 'TikTok', 'title' => 'TikTok short video', 'description' => 'A lively video opens with a clear hook and connects the product to a story that feels natural to my content.', 'price' => 850],
                ],
                categories: ['Beauty', 'Wellness'],
                faqs: [
                    ['question' => 'Do you work with sensitive-skin products?', 'answer' => 'Yes, I am careful to share clear context and only feature products I can discuss honestly.'],
                    ['question' => 'Can a brand request a routine video?', 'answer' => 'Yes. Send the product details and goals so we can discuss a good fit.'],
                ],
            ),
            new Creator('elena-garcia', 'Elena Garcia', 'Fashion', 'Madrid, Spain', 'I build personal style around pieces I already love, repeat wear, and vintage finds. I share outfits for real days, thoughtful shopping tips, and ways to make the clothes we own feel fresh again. Style should be personal, comfortable, and kind to the planet.', [
                ['platform' => 'Instagram', 'handle' => '@elenaeveryday', 'followers' => 68900, 'lastUpdated' => '2026-09-19'],
                ['platform' => 'TikTok', 'handle' => '@elenaeveryday', 'followers' => 55700, 'lastUpdated' => '2026-09-19'],
            ], ['Personal style', 'Vintage', 'Slow fashion'], 'https://images.unsplash.com/photo-1534751516642-a1af1ef26a56?auto=format&fit=crop&w=720&q=85', DemoTranslations::creator('elena-garcia'),
                tagline: 'Wear what you love, more often.',
                portfolio: [
                    ['id' => 'elena-item-1', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1483985988355-763728e1935b?auto=format&fit=crop&w=1000&q=85', 'title' => 'Everyday personal style', 'platform' => 'Instagram'],
                    ['id' => 'elena-item-2', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1490481651871-ab68de25d43d?auto=format&fit=crop&w=1000&q=85', 'title' => 'A smaller wardrobe', 'platform' => 'TikTok'],
                    ['id' => 'elena-item-3', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1485230895905-ec40ba36b9bc?auto=format&fit=crop&w=1000&q=85', 'title' => 'Vintage, restyled', 'platform' => 'Instagram'],
                    ['id' => 'elena-item-4', 'type' => 'image', 'url' => 'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?auto=format&fit=crop&w=1000&q=85', 'title' => 'A look worth repeating', 'platform' => 'Instagram'],
                ],
                packages: [
                    ['id' => 'elena-style-set', 'copyKey' => 'instagram-carousel', 'platform' => 'Instagram', 'title' => 'Instagram photo carousel', 'description' => 'A carousel of original photos and short captions walks my audience through the product, its details, and my personal take.', 'price' => 400],
                    ['id' => 'elena-style-reel', 'copyKey' => 'instagram-reel', 'platform' => 'Instagram', 'title' => 'Instagram Reel', 'description' => 'An original short video connects the product to a small everyday story, with a clear creative focus and brand tag.', 'price' => 560],
                    ['id' => 'elena-look-stories', 'copyKey' => 'instagram-story', 'platform' => 'Instagram', 'title' => 'Instagram story set', 'description' => 'Three thoughtful story frames introduce the product in a natural moment, with a brand tag and a short message that fits my usual content.', 'price' => 260],
                ],
                categories: ['Fashion', 'Lifestyle'],
                faqs: [
                    ['question' => 'Do you feature pre-loved clothing?', 'answer' => 'Yes. Vintage and pre-loved pieces are a natural part of my personal style.'],
                    ['question' => 'Can you create looks for a specific occasion?', 'answer' => 'Yes, include the occasion and any styling references with your request.'],
                ],
            ),
        ];

        $companies = [
            new Company('good-earth', 'Good Earth', 'Food & drink', null, true, DemoTranslations::company('good-earth')),
            new Company('field-notes', 'Field Notes', 'Travel & outdoors', null, true, DemoTranslations::company('field-notes')),
            new Company('soft-form', 'Soft Form', 'Wellness', null, false, DemoTranslations::company('soft-form')),
        ];

        foreach ($creators as $index => $creator) {
            $existing = $this->entityManager->getRepository(Creator::class)->findOneBy(['slug' => $creator->getSlug()]);
            if ($existing instanceof Creator) {
                if ($existing->getOwner() === null) {
                    $existing->setTranslations($creator->getTranslations());
                    $existing->setBio($creator->getBio());
                    $existing->setTagline($creator->getTagline());
                    $existing->setPortfolio($creator->getPortfolio());
                    $existing->setPackages($creator->getPackages());
                    $existing->setCategories($creator->getCategories());
                    $existing->setFaqs($creator->getFaqs());
                }
                $creators[$index] = $existing;
            } else {
                $this->entityManager->persist($creator);
            }
        }
        foreach ($companies as $index => $company) {
            $existing = $this->entityManager->getRepository(Company::class)->findOneBy(['slug' => $company->getSlug()]);
            if ($existing instanceof Company) {
                $existing->setTranslations($company->getTranslations());
                $companies[$index] = $existing;
            } else {
                $this->entityManager->persist($company);
            }
        }
        $this->entityManager->flush();

        $today = new DateTimeImmutable('today');
        $campaigns = [
            new Campaign('the-sunday-table', 'The Sunday Table', 'Help us make the everyday meal feel like a little occasion.', 'We are looking for food creators who love a good table and a simple, seasonal recipe. Share an original Sunday recipe featuring one of our pantry staples. We want natural light, genuine conversation, and the kind of meal your audience would actually make.', 'Food', ['Instagram', 'TikTok'], ['1 short-form video', '3 story frames'], 450, 900, 'United States', 5, $today->modify('+18 days'), $today, $companies[0], true, translations: DemoTranslations::campaign('the-sunday-table')),
            new Campaign('take-the-scenic-route', 'Take the scenic route', 'Show your audience a nearby place worth slowing down for.', 'Field Notes is building a collection of local guides for the curious. Take us somewhere close to home, share what makes it special, and bring our journal along for the journey. Open to creators across the US.', 'Travel', ['Instagram', 'YouTube'], ['1 photo carousel', '1 short-form video'], 600, 1400, 'United States', 4, $today->modify('+24 days'), $today->modify('-2 days'), $companies[1], true, translations: DemoTranslations::campaign('take-the-scenic-route')),
            new Campaign('a-moment-to-reset', 'A moment to reset', 'A small, honest ritual for the middle of a busy day.', 'Create a short video sharing a reset ritual that feels like you. We are not looking for a perfect routine: just a real moment, a little breathing room, and an introduction to Soft Form body care.', 'Wellness', ['Instagram', 'TikTok'], ['1 short-form video', '2 story frames'], 350, 750, 'United States & UK', 6, $today->modify('+12 days'), $today->modify('-1 day'), $companies[2], translations: DemoTranslations::campaign('a-moment-to-reset')),
            new Campaign('pantry-to-party', 'Pantry to party', 'Turn five pantry staples into a dinner friends will remember.', 'Good Earth wants to celebrate the joy of cooking for people you love. Make a short, welcoming recipe video with our pantry range and tell us who you would invite to the table.', 'Food', ['Instagram', 'TikTok'], ['1 short-form video'], 500, 1000, 'United States', 3, $today->modify('+30 days'), $today->modify('-4 days'), $companies[0], translations: DemoTranslations::campaign('pantry-to-party')),
            new Campaign('leave-it-better', 'Leave it better', 'A weekend outdoors, packed thoughtfully and left as found.', 'Field Notes is looking for outdoor storytellers to share their favorite low-impact day trip. Bring along the essentials you already love and a notebook to capture the details.', 'Travel', ['Instagram', 'YouTube'], ['1 photo carousel', '1 story set'], 700, 1600, 'United States & Canada', 4, $today->modify('+36 days'), $today->modify('-6 days'), $companies[1], translations: DemoTranslations::campaign('leave-it-better')),
        ];

        foreach ($campaigns as $campaign) {
            $existing = $this->entityManager->getRepository(Campaign::class)->findOneBy(['slug' => $campaign->getSlug()]);
            if ($existing instanceof Campaign) {
                $existing->setTranslations($campaign->getTranslations());
            } else {
                $this->entityManager->persist($campaign);
            }
        }
        $this->entityManager->flush();

        $io->success('Wave demo records and all five locale translations are ready.');

        return Command::SUCCESS;
    }
}
