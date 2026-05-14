USE `new`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- Demo seed for the social platform.
-- Recommended login password for all seeded users: Demo123!
-- Password hash generated with PHP password_hash().

DELETE FROM `notifications` WHERE `id` BETWEEN 9001 AND 9099;
DELETE FROM `messages` WHERE `id` BETWEEN 8001 AND 8099;
DELETE FROM `conversations` WHERE `id` BETWEEN 7001 AND 7099;
DELETE FROM `follows` WHERE `id` BETWEEN 6001 AND 6099;
DELETE FROM `post_likes` WHERE `id` BETWEEN 5001 AND 5099;
DELETE FROM `comments` WHERE `id` BETWEEN 4001 AND 4099;
DELETE FROM `group_members` WHERE `id` BETWEEN 3101 AND 3199;
DELETE FROM `posts` WHERE `id` BETWEEN 2001 AND 2199;
DELETE FROM `gigs` WHERE `id` BETWEEN 9501 AND 9599;
DELETE FROM `groups` WHERE `id` BETWEEN 3001 AND 3099;
DELETE FROM `users` WHERE `id` BETWEEN 1001 AND 1099;

INSERT INTO `users`
(`id`,`username`,`email`,`password_hash`,`full_name`,`role`,`avatar`,`bio`,`skills`,`country`,`timezone`,`link_tiktok`,`link_instagram`,`link_x`,`link_portfolio`,`is_premium`,`last_login_at`,`last_login_ip`,`created_at`,`updated_at`)
VALUES
(1001,'mayaalvarez','maya.alvarez@demoapp.test','$2y$10$N8usCZFNBZy1N6vbrmI2G.ulwtmu88eBdX5U/UgvPsutdHWCAhgj2','Maya Alvarez','owner','https://ui-avatars.com/api/?name=Maya+Alvarez&background=dbeafe&color=1d4ed8&rounded=true&size=160','Building creator-led products and mentoring early teams on product systems.','Product Strategy, Communities, Growth, UX','Spain','Europe/Madrid',NULL,'https://instagram.com/maya.alvarez','https://x.com/mayaalvarez','https://mayaalvarez.dev',1,'2026-04-14 08:12:00','127.0.0.1','2026-03-18 09:00:00','2026-04-14 08:12:00'),
(1002,'noahchen','noah.chen@demoapp.test','$2y$10$N8usCZFNBZy1N6vbrmI2G.ulwtmu88eBdX5U/UgvPsutdHWCAhgj2','Noah Chen','admin','https://ui-avatars.com/api/?name=Noah+Chen&background=dbeafe&color=1d4ed8&rounded=true&size=160','Ops-minded builder helping communities ship cleaner systems and internal tooling.','Operations, PHP, Moderation, Analytics','Singapore','Asia/Singapore',NULL,NULL,'https://x.com/noahchenbuilds','https://noahchen.build',1,'2026-04-14 08:21:00','127.0.0.1','2026-03-19 10:10:00','2026-04-14 08:21:00'),
(1003,'lenaorlov','lena.orlov@demoapp.test','$2y$10$N8usCZFNBZy1N6vbrmI2G.ulwtmu88eBdX5U/UgvPsutdHWCAhgj2','Lena Orlov','moderator','https://ui-avatars.com/api/?name=Lena+Orlov&background=dbeafe&color=1d4ed8&rounded=true&size=160','I moderate growth communities and love turning scattered ideas into repeatable workflows.','Community Ops, Moderation, Notion, Content','Poland','Europe/Warsaw',NULL,'https://instagram.com/lena.orlov',NULL,'https://lenaorlov.co',0,'2026-04-13 22:15:00','127.0.0.1','2026-03-21 11:00:00','2026-04-13 22:15:00'),
(1004,'zaraibrahim','zara.ibrahim@demoapp.test','$2y$10$N8usCZFNBZy1N6vbrmI2G.ulwtmu88eBdX5U/UgvPsutdHWCAhgj2','Zara Ibrahim','member','https://ui-avatars.com/api/?name=Zara+Ibrahim&background=dbeafe&color=1d4ed8&rounded=true&size=160','Creator marketer documenting audience growth, funnels, and sustainable launch systems.','Creator Marketing, Launches, Email, Strategy','United Kingdom','Europe/London','https://tiktok.com/@zaraibrahim','https://instagram.com/zaraibrahim','https://x.com/zaraibrahim','https://zaraibrahim.com',1,'2026-04-14 07:58:00','127.0.0.1','2026-03-24 09:35:00','2026-04-14 07:58:00'),
(1005,'marcusreed','marcus.reed@demoapp.test','$2y$10$N8usCZFNBZy1N6vbrmI2G.ulwtmu88eBdX5U/UgvPsutdHWCAhgj2','Marcus Reed','member','https://ui-avatars.com/api/?name=Marcus+Reed&background=dbeafe&color=1d4ed8&rounded=true&size=160','Designing landing pages, product positioning, and onboarding experiences for SaaS teams.','Brand, Design Systems, Landing Pages, CRO','United States','America/New_York',NULL,'https://instagram.com/marcusreed.design','https://x.com/marcusreed','https://marcusreed.design',0,'2026-04-13 20:40:00','127.0.0.1','2026-03-25 14:00:00','2026-04-13 20:40:00'),
(1006,'priyashah','priya.shah@demoapp.test','$2y$10$N8usCZFNBZy1N6vbrmI2G.ulwtmu88eBdX5U/UgvPsutdHWCAhgj2','Priya Shah','member','https://ui-avatars.com/api/?name=Priya+Shah&background=dbeafe&color=1d4ed8&rounded=true&size=160','Full-stack developer sharing build logs, code reviews, and product feedback loops.','PHP, JavaScript, APIs, Product Engineering','India','Asia/Kolkata',NULL,NULL,'https://x.com/priyashahcodes','https://priyashah.dev',1,'2026-04-14 09:02:00','127.0.0.1','2026-03-26 08:50:00','2026-04-14 09:02:00'),
(1007,'ethanwalker','ethan.walker@demoapp.test','$2y$10$N8usCZFNBZy1N6vbrmI2G.ulwtmu88eBdX5U/UgvPsutdHWCAhgj2','Ethan Walker','member','https://ui-avatars.com/api/?name=Ethan+Walker&background=dbeafe&color=1d4ed8&rounded=true&size=160','Helping solo founders turn rough ideas into MVP roadmaps and clear weekly execution.','Startup Ops, Roadmaps, Validation','Canada','America/Toronto',NULL,NULL,'https://x.com/ethanwalker','https://ethanwalker.io',0,'2026-04-13 19:18:00','127.0.0.1','2026-03-28 12:25:00','2026-04-13 19:18:00'),
(1008,'sofiamoreno','sofia.moreno@demoapp.test','$2y$10$N8usCZFNBZy1N6vbrmI2G.ulwtmu88eBdX5U/UgvPsutdHWCAhgj2','Sofia Moreno','member','https://ui-avatars.com/api/?name=Sofia+Moreno&background=dbeafe&color=1d4ed8&rounded=true&size=160','I write about creative process, storytelling, and turning client work into repeatable assets.','Copywriting, Storytelling, Content Systems','Mexico','America/Mexico_City','https://tiktok.com/@sofiawrites','https://instagram.com/sofiamoreno','https://x.com/sofiamoreno','https://sofiamoreno.studio',0,'2026-04-13 21:32:00','127.0.0.1','2026-03-29 16:45:00','2026-04-13 21:32:00');

INSERT INTO `follows`
(`id`,`follower_id`,`following_id`,`created_at`)
VALUES
(6001,1004,1001,'2026-04-06 09:00:00'),
(6002,1004,1006,'2026-04-06 09:05:00'),
(6003,1005,1001,'2026-04-06 10:00:00'),
(6004,1005,1004,'2026-04-06 10:05:00'),
(6005,1006,1001,'2026-04-06 11:00:00'),
(6006,1006,1003,'2026-04-06 11:05:00'),
(6007,1007,1004,'2026-04-06 12:00:00'),
(6008,1008,1005,'2026-04-06 13:00:00'),
(6009,1008,1001,'2026-04-06 13:05:00');

INSERT INTO `groups`
(`id`,`name`,`slug`,`description`,`cover_image`,`is_code_only`,`created_by`,`is_private`,`is_paid`,`price_month`,`invite_token`,`created_at`)
VALUES
(3001,'Frontend Systems Lab','frontend-systems-lab','A practical build-and-review group for frontend architecture, component systems, and UI polish.','https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1400&q=80',1,1001,0,0,NULL,NULL,'2026-04-03 10:00:00'),
(3002,'Creator Growth Mastermind','creator-growth-mastermind','A higher-trust room for creators working on audience growth, launches, and premium offers.','https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=1400&q=80',0,1004,1,1,29.00,'growthmasterminddemo2026','2026-04-04 12:15:00'),
(3003,'Indie Product Feedback','indie-product-feedback','An open group for shipping fast, getting honest product feedback, and improving MVPs weekly.','https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=1400&q=80',0,1006,0,0,NULL,NULL,'2026-04-05 15:20:00');

INSERT INTO `group_members`
(`id`,`group_id`,`user_id`,`role`,`created_at`)
VALUES
(3101,3001,1001,'owner','2026-04-03 10:01:00'),
(3102,3001,1006,'moderator','2026-04-03 10:20:00'),
(3103,3001,1005,'member','2026-04-03 10:25:00'),
(3104,3001,1007,'member','2026-04-03 10:35:00'),
(3105,3002,1004,'owner','2026-04-04 12:16:00'),
(3106,3002,1001,'member','2026-04-04 13:00:00'),
(3107,3002,1008,'member','2026-04-04 13:05:00'),
(3108,3003,1006,'owner','2026-04-05 15:21:00'),
(3109,3003,1003,'member','2026-04-05 16:00:00'),
(3110,3003,1005,'member','2026-04-05 16:10:00'),
(3111,3003,1008,'member','2026-04-05 16:20:00');

INSERT INTO `posts`
(`id`,`user_id`,`group_id`,`title`,`body`,`category`,`is_pinned`,`created_at`,`updated_at`)
VALUES
(2001,1001,NULL,'The difference a clean onboarding flow makes','Spent the morning reviewing onboarding friction for a creator SaaS. The biggest unlock was not another feature, it was better sequencing. Fewer decisions up front, more clarity, and one visible next step outperformed every extra tooltip.','highlight',0,'2026-04-10 08:15:00','2026-04-10 08:15:00'),
(2002,1004,NULL,'Three launch lessons from this week','Short version: pre-sell earlier, talk to people before polishing the offer page, and publish the behind-the-scenes while energy is high. The messy middle content usually converts better than the polished summary.','post',0,'2026-04-10 10:10:00','2026-04-10 10:10:00'),
(2003,1006,NULL,'Project update: internal analytics dashboard','Wrapped the first stable version of our internal analytics dashboard. It now tracks activation events, retention checkpoints, and team handoff notes in one place. Next step is reducing noise so the alerts feel actionable instead of constant.','project',0,'2026-04-10 12:40:00','2026-04-10 12:40:00'),
(2004,1005,NULL,'Landing page review note','A homepage headline does not need to explain everything. It needs to create clarity fast enough that the reader wants the next line. I still see too many pages trying to stack six promises in one sentence.','post',0,'2026-04-11 09:05:00','2026-04-11 09:05:00'),
(2005,1008,NULL,'The best content workflow I found this quarter','I stopped batching ideas by platform and started batching by story. One strong angle now becomes a thread, a short video, a long-form post, and an email. The work feels lighter because the core thinking happens once.','highlight',0,'2026-04-11 18:25:00','2026-04-11 18:25:00'),
(2006,1002,NULL,'Admin note on community quality','The healthiest communities are not the loudest ones. They are the ones where people can tell what is useful, what belongs, and what gets followed up. Consistency beats hype every time.','post',0,'2026-04-12 07:45:00','2026-04-12 07:45:00'),
(2101,1001,3001,'Reusable card system checklist','For anyone shipping fast in UI work: define your spacing scale first, then button states, then card hierarchy. If the system is stable, the product starts looking deliberate even before the fancy polish lands.','post',1,'2026-04-12 09:20:00','2026-04-12 09:20:00'),
(2102,1006,3001,'Debugging a flaky form flow','I traced a weird validation issue back to two fields trying to own the same state. Once the source of truth was simplified, the UI errors disappeared. Good reminder that unstable forms usually mean unstable data flow, not just bad styling.','post',0,'2026-04-12 11:30:00','2026-04-12 11:30:00'),
(2103,1004,3002,'April mastermind prompt','Share the one offer you are tightening this month, the bottleneck you are seeing, and the simplest experiment you can run this week. Keep it specific enough that the group can actually help.','post',1,'2026-04-12 13:15:00','2026-04-12 13:15:00'),
(2104,1008,3002,'Content angle that surprised me','The post that performed best last week was not polished at all. It was a simple note about what almost went wrong during a launch. People respond to honesty faster than polish when the lesson is clear.','post',0,'2026-04-12 18:10:00','2026-04-12 18:10:00'),
(2105,1006,3003,'Need feedback on our onboarding checklist','We are trying to reduce the time from signup to first useful action. Right now the checklist feels informative, but not motivating. Curious how you all decide which early actions deserve the most attention.','post',0,'2026-04-13 09:40:00','2026-04-13 09:40:00'),
(2106,1005,3003,'Positioning question for an MVP','Would you rather see an MVP position itself around one urgent use case or present a broader platform vision if the product still has obvious rough edges? I keep leaning toward narrower, but interested in the tradeoffs.','post',0,'2026-04-13 16:55:00','2026-04-13 16:55:00');

INSERT INTO `comments`
(`id`,`post_id`,`user_id`,`body`,`created_at`)
VALUES
(4001,2001,1004,'This is exactly what we saw on our last launch page. The second step matters more than people think.','2026-04-10 08:40:00'),
(4002,2002,1001,'The point about shipping the messy middle content early is so real. That usually becomes the strongest trust-builder.','2026-04-10 10:25:00'),
(4003,2003,1005,'Would love to see how you''re deciding which alerts deserve escalation vs just logging.','2026-04-10 13:05:00'),
(4004,2005,1006,'Batching by story instead of platform is a great way to keep quality up without burning out.','2026-04-11 18:40:00'),
(4005,2101,1006,'Pinned for a reason. This checklist would save so many rushed frontends from drifting.','2026-04-12 09:45:00'),
(4006,2102,1001,'Great catch. Shared state bugs always feel visual until you trace them properly.','2026-04-12 11:42:00'),
(4007,2103,1008,'My bottleneck is offer clarity right now. I can share screenshots later today.','2026-04-12 13:40:00'),
(4008,2104,1004,'That kind of honest process content usually gets the strongest replies for me too.','2026-04-12 18:26:00'),
(4009,2105,1003,'I would test a version where the first checklist item feels like a win in under two minutes.','2026-04-13 10:05:00'),
(4010,2106,1008,'I still prefer the narrow use-case angle if the product is early. It gives people something concrete to remember.','2026-04-13 17:18:00');

INSERT INTO `post_likes`
(`id`,`post_id`,`user_id`,`created_at`)
VALUES
(5001,2001,1004,'2026-04-10 08:32:00'),
(5002,2001,1005,'2026-04-10 08:50:00'),
(5003,2001,1006,'2026-04-10 09:02:00'),
(5004,2002,1001,'2026-04-10 10:18:00'),
(5005,2002,1008,'2026-04-10 10:21:00'),
(5006,2003,1005,'2026-04-10 12:58:00'),
(5007,2003,1007,'2026-04-10 13:08:00'),
(5008,2004,1004,'2026-04-11 09:30:00'),
(5009,2005,1006,'2026-04-11 18:30:00'),
(5010,2005,1001,'2026-04-11 18:35:00'),
(5011,2101,1006,'2026-04-12 09:35:00'),
(5012,2101,1005,'2026-04-12 09:36:00'),
(5013,2102,1001,'2026-04-12 11:35:00'),
(5014,2103,1001,'2026-04-12 13:18:00'),
(5015,2104,1004,'2026-04-12 18:18:00'),
(5016,2105,1003,'2026-04-13 09:52:00'),
(5017,2106,1006,'2026-04-13 17:02:00');

INSERT INTO `conversations`
(`id`,`user1_id`,`user2_id`,`created_at`)
VALUES
(7001,1001,1004,'2026-04-08 09:00:00'),
(7002,1004,1006,'2026-04-09 12:00:00'),
(7003,1005,1008,'2026-04-10 16:10:00');

INSERT INTO `messages`
(`id`,`conversation_id`,`sender_id`,`body`,`created_at`,`read_at`)
VALUES
(8001,7001,1001,'Hey Zara, loved the launch recap you posted this morning. Want to turn that into a pinned thread for the mastermind group later this week?','2026-04-08 09:05:00','2026-04-08 09:11:00'),
(8002,7001,1004,'Yes, definitely. I can clean up the main points and add a short framework so it is easier to discuss.','2026-04-08 09:10:00','2026-04-08 09:12:00'),
(8003,7001,1001,'Perfect. Keep it practical and we will let the group build on it.','2026-04-08 09:12:00','2026-04-08 09:14:00'),
(8004,7002,1004,'Priya, quick question. In your dashboard project post, are you calculating activation by first value event or first session milestone?','2026-04-09 12:05:00','2026-04-09 12:20:00'),
(8005,7002,1006,'Right now first value event. I tried session milestones first and it inflated the signal too much.','2026-04-09 12:18:00','2026-04-09 12:22:00'),
(8006,7002,1004,'That makes sense. If you document the tradeoff, I think it would make a strong follow-up post.','2026-04-09 12:21:00','2026-04-09 12:24:00'),
(8007,7003,1005,'Sofia, your storytelling workflow post was excellent. Are you turning that into a client-facing framework too?','2026-04-10 16:12:00','2026-04-10 16:20:00'),
(8008,7003,1008,'Thank you. I am drafting it into a workshop deck right now. The challenge is keeping it simple enough to teach.','2026-04-10 16:18:00','2026-04-10 16:21:00'),
(8009,7003,1005,'If you want, send me the outline later. Happy to give it a positioning pass.','2026-04-10 16:21:00',NULL),
(8010,7003,1008,'I will. That would actually help a lot before I publish it.','2026-04-10 16:24:00',NULL);

INSERT INTO `notifications`
(`id`,`to_user_id`,`from_user_id`,`type`,`ref_id`,`message`,`is_read`,`created_at`,`read_at`)
VALUES
(9001,1001,1004,'follow',1004,'started following you.',0,'2026-04-06 09:00:00',NULL),
(9002,1004,1001,'like',2002,'liked your post.',0,'2026-04-10 10:18:00',NULL),
(9003,1006,1005,'comment',2003,'commented on your post.',0,'2026-04-10 13:05:00',NULL),
(9004,1004,1001,'message',7001,'sent you a message.',1,'2026-04-08 09:05:00','2026-04-08 09:11:00'),
(9005,1001,1006,'comment',2102,'commented on your group post.',0,'2026-04-12 11:42:00',NULL),
(9006,1004,1008,'comment',2104,'commented on your group post.',0,'2026-04-12 18:26:00',NULL),
(9007,1005,1008,'message',7003,'sent you a message.',0,'2026-04-10 16:24:00',NULL),
(9008,1001,1006,'like',2101,'liked your group post.',0,'2026-04-12 09:35:00',NULL);

INSERT INTO `gigs`
(`id`,`user_id`,`title`,`price_from`,`rating`,`created_at`)
VALUES
(9501,1005,'Landing Page Messaging Review',150.00,4.90,'2026-04-07 11:00:00'),
(9502,1006,'PHP Product Sprint Support',320.00,4.80,'2026-04-08 14:20:00'),
(9503,1008,'Creator Content System Audit',210.00,4.95,'2026-04-09 17:45:00');

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
