<?php

namespace SocialPoster\Support\Text;

/**
 * Top-level domains that make a scheme-less "domain.tld" a link, the way X
 * (twitter-text) and most platforms autolink bare domains.
 *
 * Every delegated ccTLD is listed. The generic list is a curated subset of the
 * IANA root zone (the common legacy and new gTLDs), plus the common
 * internationalised TLDs in their Unicode form ("twitter.みんな"); a bare
 * domain on a rarer TLD is counted at its literal length. URLs written with
 * http(s):// fall back to their last label when no known TLD fits (see Urls).
 *
 * @internal
 */
final class Tlds
{
    private const COUNTRY = 'ac ad ae af ag ai al am ao aq ar as at au aw ax az ba bb bd be bf bg bh bi bj bm bn bo br bs '
        .'bt bv bw by bz ca cc cd cf cg ch ci ck cl cm cn co cr cu cv cw cx cy cz de dj dk dm do dz ec ee eg er es '
        .'et eu fi fj fk fm fo fr ga gb gd ge gf gg gh gi gl gm gn gp gq gr gs gt gu gw gy hk hm hn hr ht hu id ie '
        .'il im in io iq ir is it je jm jo jp ke kg kh ki km kn kp kr kw ky kz la lb lc li lk lr ls lt lu lv ly ma '
        .'mc md me mg mh mk ml mm mn mo mp mq mr ms mt mu mv mw mx my mz na nc ne nf ng ni nl no np nr nu nz om pa '
        .'pe pf pg ph pk pl pm pn pr ps pt pw py qa re ro rs ru rw sa sb sc sd se sg sh si sj sk sl sm sn so sr ss '
        .'st su sv sx sy sz tc td tf tg th tj tk tl tm tn to tr tt tv tw tz ua ug uk us uy uz va vc ve vg vi vn vu '
        .'wf ws ye yt za zm zw';

    private const GENERIC = 'com net org edu gov mil int arpa info biz name pro aero asia cat coop jobs mobi museum post '
        .'tel travel xxx academy accountant accountants actor adult agency airforce amazon android apartments app '
        .'apple archi army art associates attorney auction audio auto autos baby band bank bar bargains bbc beer '
        .'berlin best bet bible bid bike bingo bio black blackfriday blog blue bmw boats bond boo book boutique box '
        .'broker build builders business buzz cab cafe cam camera camp capital car cards care career careers cars '
        .'casa cash casino catering center ceo charity chat cheap christmas church city claims cleaning click clinic '
        .'clothing cloud club coach codes coffee college community company computer condos construction consulting '
        .'contractors cooking cool country coupons courses credit creditcard cricket cruises cymru dance date dating '
        .'day deals degree delivery democrat dental dentist design dev diamonds diet digital direct directory '
        .'discount doctor dog domains download earth eco education email energy engineer engineering enterprises '
        .'equipment estate eus events exchange expert exposed express fail faith family fan fans farm fashion film '
        .'finance financial fish fishing fit fitness flights florist flowers fly foo food football forsale foundation '
        .'free fun fund furniture futbol fyi gallery game games garden gay gift gifts gives glass global gmbh gmail '
        .'gold golf goog google graphics gratis green gripe group guide guitars guru hair haus health healthcare '
        .'help hiphop hockey holdings holiday homes horse hospital host hosting house how icu immo immobilien inc '
        .'industries ink institute insure international investments irish jetzt jewelry juegos kaufen kim kitchen '
        .'kiwi land lat law lawyer lease legal lgbt life lighting limited limo link live llc llp loan loans lol '
        .'london love ltd luxury maison management market marketing mba media meme memorial men menu miami '
        .'microsoft moda moe mom money monster mortgage motorcycles mov movie music nagoya navy network new news '
        .'ngo ninja now nyc observer okinawa one ong onl online ooo organic osaka page paris partners parts party '
        .'pet phd photo photography photos pics pictures pink pizza place plumbing plus poker porn press '
        .'productions prof promo properties property pub qpon quebec racing radio realestate realty recipes red '
        .'rehab reise reisen rent rentals repair report republican rest restaurant review reviews rich rip rocks '
        .'rodeo rsvp rugby run sale salon sarl school schule science scot security select services sex sexy '
        .'shiksha shoes shop shopping show singles site ski skin soccer social software solar solutions soy spa '
        .'space sport spot srl storage store stream studio study style sucks supplies supply support surf surgery '
        .'sydney systems taipei tattoo tax taxi team tech technology tennis theater theatre tickets tienda tips '
        .'tires today tokyo tools top tours town toys trade trading training tube university uno vacations vegas '
        .'ventures vet viajes video villas vin vip vision vodka vote voting voto voyage wales wang watch webcam '
        .'website wed wedding whoswho wiki win wine work works world wtf xyz yachts yoga yokohama youtube zone';

    /** Internationalised TLDs as written in text (punycode "xn--..." spellings are always known). */
    private const INTERNATIONAL = 'みんな コム セール ストア ポイント クラウド ファッション グーグル '
        .'中国 中國 中文网 公司 网络 网址 网店 网站 在线 商城 商标 手机 游戏 我爱你 集团 世界 信息 企业 娱乐 健康 购物 时尚 '
        .'香港 台灣 台湾 新加坡 澳門 澳门 한국 닷컴 닷넷 삼성 '
        .'рф рус бел укр срб мкд қаз мон бг онлайн сайт орг ком дети москва '
        .'ไทย ελ ευ հայ გე קום '
        .'مصر السعودية امارات ایران قطر الاردن عمان فلسطين تونس الجزائر المغرب عراق سورية موقع شبكة كوم عرب';

    /** @var array<string, true>|null */
    private static ?array $index = null;

    public static function isKnown(string $tld): bool
    {
        self::$index ??= array_fill_keys(
            array_merge(explode(' ', self::COUNTRY), explode(' ', self::GENERIC), explode(' ', self::INTERNATIONAL)),
            true,
        );

        $tld = mb_strtolower($tld, 'UTF-8');

        return isset(self::$index[$tld]) || str_starts_with($tld, 'xn--');
    }
}
