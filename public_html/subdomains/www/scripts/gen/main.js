<?php
$libDir = __DIR__.'/../../../../lib';
require_once $libDir.'/utils/utils.php';
require_once __DIR__.'/popup.js';

$root = get_root_link();
$res = get_root_link('res');
$isAuth = (int)isset($_COOKIE['sid']);

function getIndexElems() {
    global $isAuth, $root, $res;
    $getDisconnectElemHTML = getDisconnectElem()['html'];
    $getDisconnectElemJS = getDisconnectElem()['js'];
    $getEditAvatarHTML = getEditAvatar()['html'];
    $getEditAvatarJS = getEditAvatar()['js'];
    return ['html' => <<<HTML
    <div id="topBar" style="display:none;">
        <div id="topBar_r">
            <a id="topBar_r_slideArea" href="#" onclick="return false;">
                <div id="topBar_r_recentEvents" style="display:none;">
                
                </div> 
                <p class="username"></p>
                <img class="avatar" src="$res/avatars/empty.jpg" />
            </a>
        </div>
    </div>
    <div id="rightBar" style="display:none">
        <div id="rightBar_titleDiv">
            <div id="rightBar_titleDiv_collapse">
                <img src="$res/design/collapseRight.png" />
            </div>
            <p></p>
        </div>
        <div id="rightBar_optionsDiv">
            <div class="primary">
                <a id="rightBar_optionsDiv_forum" href="$root/forum" onclick="return false;">
                    <div class="imgContainer">
                        <img src="$res/icons/speech_bubble.svg" style="transform: translate(0%,9%);" />
                    </div>
                    <p>Forum</p>
                </a>
                <a id="rightBar_optionsDiv_userSettings" href="$root/usersettings" onclick="return false;">
                    <div class="imgContainer">
                        <img src="$res/icons/gears.svg" />
                    </div>
                    <p>Paramètres</p>
                </a>
            </div>
            
            <div class="more">
                <a id="rightBar_more_button" href="#" onclick="return false;">
                    <div class="imgContainer">
                        <img src="$res/icons/arrow.svg" />
                    </div>
                    <p>Plus d'options...</p>
                </a>
                <div>
                    <a id="rightBar_optionsDiv_editAvatar" href="#" onclick="return false;">
                        <p>Changer d'avatar</p>
                    </a>
                    <a id="rightBar_optionsDiv_versionHistory" href="$root/versionhistory" onclick="return false">
                        <p>Historique versions</p>
                    </a>
                    <a id="rightBar_optionsDiv_contribute" href="https://opencollective.com/site-interessant/donate?interval=oneTime&amount=10&contributeAs=me" target="_blank">
                        <p>Financer</p>
                    </a>
                    <a id="rightBar_optionsDiv_disconnect" href="#" onclick="return false;">
                        <p>Se Déconnecter</p>
                    </a>
                </div>
            </div>
        </div>
        <div id="rightBar_notificationsDiv">
            <p class="sectionTitle">Notifications</p>
            <div id="rightBar_recentEvents">

            </div>
            <div id="rightBar_history">
                <p class="sectionTitle">Historique</p>
                <div></div>
            </div>
        </div>
    </div>

    HTML,
    'js' => <<<JAVASCRIPT
    const topBar = document.querySelector('#topBar');
    const rightBar = document.querySelector('#rightBar');

    function openRightBar() {
        rightBar.setAttribute('open',1);
        topBarRSlideArea.style.cursor = 'default';
        topBarRSlideArea.dispatchEvent(new MouseEvent('mouseleave'));
    }
    function closeRightBar() {
        rightBar.setAttribute('open',0);
        topBarRSlideArea.style.cursor = 'pointer';
    }

    let gettingEvents = false;
    async function getRecentEvents() {
        if (gettingEvents) return; else gettingEvents = true;
        const notifCont = rightBar.querySelector('#rightBar_recentEvents');
        const histCont = rightBar.querySelector('#rightBar_history > div');
        notifCont.innerHTML = '<p>Loading...</p>';
        return sendQuery(`query {
            viewer {
                dbId
                notifications(first:50) {
                    edges {
                        node {
                            userId
                            number
                            actionGroupName
                            actionName
                            creationDate
                            lastUpdateDate
                            readDate
                            details
                            n
                            ... on ForumNotification {
                                thread {
                                    dbId
                                    title
                                }
                                comment {
                                    id
                                    author {
                                        name
                                    }
                                }
                                users:associatedUsers {
                                    name
                                }
                            }
                        }
                    }
                }
            }
            records(first:150) {
                edges {
                    node {
                        dbId
                        associatedUser {
                            name
                        }
                        actionGroupName
                        actionName
                        details
                        date
                        notifiedIds
                        thread:associatedThread {
                            dbId
                            title
                        }
                    }
                }
            }
        }`).then((json) => {
            if (json?.data?.records?.edges == null || json?.data?.viewer?.notifications?.edges == null) { basicQueryResultCheck(); return; }
            gettingEvents = false;
            const records = json.data.records;
            const notifications = json.data.viewer.notifications;
            const userId = json.data.viewer.dbId;
            notifCont.innerHTML = '';
            histCont.innerHTML = '';

            let recentEventsN = 0;
            function setRecentEventsN(n) {
                const e = topBar.querySelector('#topBar_r_recentEvents');
                if (n > 0) {
                    e.style.display = '';
                    const ss = n > 1 ? 'notifications' : 'notification';
                    e.innerHTML = `<p>\${n} \${ss}</p>`;
                } else e.style.display = 'none';  
            }

            const history = {};
            let currEvent = {firstRecord:null, name:'', n:0};
            currEvent.flush = () => {
                if (currEvent.firstRecord?.actionGroupName == null) return;
                else if (currEvent.firstRecord.actionGroupName == 'FORUM') {
                    const record = currEvent.firstRecord;
                    switch (record.actionName) {
                        case 'addComment':
                            const sComm = currEvent.n > 1 ? 'nouveaux commentaires' : 'nouveau commentaire';
                            const node = stringToNodes(`<a href="$root/forum/\${record.thread.dbId}" onclick="return false;" class="historyItem">
                                <p class="title">\${record.thread.title}</p>
                                <p class="description">\${currEvent.n} \${sComm}</p>
                            </a>`)[0];
                            node.addEventListener('click',() => { loadPage(node.href,StateAction.PushState); closeRightBar(); } );
                            histCont.insertAdjacentElement('afterbegin',node);
                            break;
                    }
                }
            }
            for (const edge of records.edges.toReversed()) {
                const record = edge.node;
                if (record.actionGroupName == 'FORUM') switch (record.actionName) {
                    case 'addComment':
                        if (currEvent.name == 'thAddComment_'+record.thread.dbId) currEvent.n++;
                        else {
                            currEvent.flush();
                            currEvent.firstRecord = record;
                            currEvent.name = 'thAddComment_'+record.thread.dbId;
                            currEvent.n = 1;
                        }
                        break;
                }
            }
            currEvent.flush();

            for (const edge of notifications.edges) {
                const notification = edge.node;
                const s = notification.n > 1 ? 'nouveaux commentaires' : 'nouveau commentaire';
                const notRead = notification.readDate == null ? ' new' : '';
                const users = notification.users;
                let names = [];
                for (let i=0; i<(users.length >= 3 ? 3 : users.length); i++) names.push(users[i].name);
                const sNames = '(' + names.join(',') + (users.length > 3 ? `, et \${users.length-3} autres...` : '') + ')'; 

                const node = stringToNodes(`<a href="$root/forum/\${notification.thread.dbId}" onclick="return false" class="notification\${notRead}">
                    <div class="notification_type">

                    </div>
                    <div class="notification_content">
                        <p class="title">\${notification.thread.title}</p>
                        <p class="desc">\${notification.n} \${s} \${sNames}</p>
                    </div>
                </a>`)[0];
                let timeout = null;
                node.addEventListener('click',() => {
                    const alreadyRead = !node.classList.contains('new');
                    node.classList.remove('new');
                    loadPage(node.href,StateAction.PushState).then(() => {
                        if (alreadyRead) return;
                        sendQuery(`mutation SetNotificationToRead(\$number:Int!) {
                            f:setNotificationToRead(number:\$number) {
                                __typename
                                success
                                resultCode
                                resultMessage
                            }
                        }`,{number:notification.number}).then((json) => {
                            if (!basicQueryResultCheck(json?.data?.f)) return;
                            
                            node.classList.remove('new');
                            setRecentEventsN(--recentEventsN);
                        });
                    });
                    closeRightBar();
                });
                node.addEventListener('mouseleave',() => { if (timeout != null) clearTimeout(timeout); timeout = null; });
                notifCont.insertAdjacentElement('beforeend',node); 
            }
            for (const edge of notifications.edges) if (edge.node.readDate == null) ++recentEventsN;
            setRecentEventsN(recentEventsN);
        });
    }

    const topBarRSlideArea = document.querySelector('#topBar_r_slideArea');
    topBarRSlideArea.addEventListener('click',openRightBar);
    let tbrTimeout = null;
    topBarRSlideArea.addEventListener('mouseenter',() => { tbrTimeout = setTimeout(openRightBar, 150); });
    topBarRSlideArea.addEventListener('mouseleave',() => { if (tbrTimeout != null) clearTimeout(tbrTimeout); });
    let rbTimeout = null;
    rightBar.addEventListener('mouseenter',() => { if (rbTimeout != null) clearTimeout(rbTimeout); });
    rightBar.addEventListener('mouseleave',() => { rbTimeout = setTimeout(closeRightBar, 150); });
    document.querySelector('#bodyDiv').addEventListener('click',closeRightBar);
    document.querySelector('#rightBar_titleDiv_collapse').addEventListener('click',closeRightBar);

    document.querySelector('#rightBar_optionsDiv_editAvatar').addEventListener('click',(e) => {
        e.preventDefault();
        const node = stringToNodes(`$getEditAvatarHTML`)[0];
        popupDiv.insertAdjacentElement('beforeend',node);
        $getEditAvatarJS
        popupDiv.openTo('#editAvatar');
    });  
    document.querySelector('#rightBar_optionsDiv_forum').addEventListener('click',(e) => {
        e.preventDefault();
        if (location.href == "$root/pages/forum") return;
        loadPage("$root/pages/forum",StateAction.PushState);
    });
    document.querySelector('#rightBar_optionsDiv_userSettings').addEventListener('click',() => loadPage("$root/pages/usersettings",StateAction.PushState));
    document.querySelector('#rightBar_optionsDiv_versionHistory').addEventListener('click',() => loadPage("$root/pages/versionhistory",StateAction.PushState));  
    document.querySelector('#rightBar_optionsDiv_disconnect').addEventListener('click',() => {
        popupDiv.insertAdjacentHTML('beforeend',`$getDisconnectElemHTML`);
        $getDisconnectElemJS
        popupDiv.openTo('#askDisconnect');
    });

    const moreDiv = document.querySelector('#rightBar_optionsDiv > .more');
    const moreBut = document.querySelector('#rightBar_more_button');
    moreBut.addEventListener('click',() => {
        if (moreDiv.classList.contains('expanded')) {
            moreDiv.classList.remove('expanded');
        } else moreDiv.classList.add('expanded');
    });

    if ($isAuth === 1) {
        topBar.style.display = rightBar.style.display = '';

        sendQuery(`query { viewer { name avatarURL } }`).then((json) => {
            if (json?.data?.viewer?.name == null) { basicQueryResultCheck(); return; }
            document.querySelector('#rightBar_titleDiv p').innerHTML =  document.querySelector('#topBar_r_slideArea .username').innerHTML = json.data.viewer.name;
            document.querySelector('#topBar_r_slideArea .avatar').src = json.data.viewer.avatarURL;
        });

        navigator.serviceWorker.addEventListener('message', (s) => {
            if (s?.data == 'checkNotifs') getRecentEvents();
        });
        getRecentEvents();
    }

    JAVASCRIPT,
    'css' => <<<CSS
    #topBar {
        position:fixed;
        background-color: black;
        color: white;
        width: 100%;
        min-height: 32px;
        height: 2rem;
        box-shadow: 0px 0px min(10px,0.5rem) 0px black;
        z-index: 10;
    }
    #topBar_r {
        position: absolute;
        right: 0px;
        height: 100%;
        display: flex;
        align-items: center;
    }
    #topBar_r_recentEvents {
        background-color: #0482FF;
        box-shadow: 0px 0px 0.4rem 0.1rem #0482FF;
        border-radius: 0.2em;
        padding: 0.15rem 0.5rem;
        font-size: 0.8rem;
    }
    #topBar_r_slideArea {
        height: 100%;
        /* width: 100%; */
        display: flex;
        align-items: center;
        padding: 0px 1rem;
        gap: 0.5rem;
        text-decoration: none;
        color: white;
    }
    #topBar_r_slideArea .username {
        color: #B7B9C6;
    }
    #topBar_r_slideArea:hover {
        background-color: var(--color-orange-1);
    }
    #topBar_r_slideArea:hover .username {
        color: white;
    }
    #topBar_r_slideArea img {
        max-height: 20px;
        max-width: 20px;
    }
    #rightBar {
        position:fixed;
        right: 0px;
        background-color: black;
        width: min(310px,70%);
        height: 100%;
        z-index: 11;
        transform: translate(100%,0%);
        transition: transform 0.15s;
        overflow: auto;
    }
    #rightBar[open="1"] {
        transform: translate(0%,0%);
        box-shadow: 0px 0px min(10px,0.5rem) 0px black;
    }
    #rightBar_titleDiv {
        height: 32px;
        width: 100%;
        background-color: var(--color-orange-1);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.4rem;
    }
    #rightBar_titleDiv > p {
        width: 88%;
        text-align: center;
    }
    #rightBar_titleDiv_collapse {
        background-color: #feb500;
        width: 12%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    #rightBar_titleDiv_collapse:hover {
        background-color: #ffe193;
        cursor: pointer;
    }
    #rightBar_optionsDiv a {
        display: flex;
        height: 2.6rem;
        width: 100%;
        text-decoration: none;
        align-items: center;
        font-size: 1.2rem;
    }   
    #rightBar_optionsDiv .imgContainer {
        width: 4rem;
        display: flex;
        height: 100%;
        justify-content: center;
    }
    #rightBar_optionsDiv > .primary {
        display: flex;
        flex-direction: column;
        align-items: center;
        background-color: var(--color-black-1);
    }
    #rightBar_optionsDiv > .primary img {
        width: 2.1rem;
        filter: drop-shadow(0px 1px 0px #2d3a68);
    }
    #rightBar_optionsDiv > .primary p,
    #rightBar_optionsDiv > .more > a:first-of-type p {
        flex: 1 100 90%;
        text-align: center;
        transform: translate(-8%, 0px);
    }
    #rightBar_optionsDiv > .more p {
        width: 100%;
        text-align: center;
    }
    #rightBar_optionsDiv > .more {
        border-bottom: 0.1rem solid #FE7D00;
    }
    #rightBar_optionsDiv > .more > a:first-of-type {
        display: flex;
        align-items: center;
        height: 1.6rem;
        font-size: 0.7rem;
    }
    #rightBar_optionsDiv > .more a {
        height: 2rem;
        font-size: 0.95rem;
    }
    #rightBar_optionsDiv > .more img {
        width: 0.7rem;
        transition: all 0.25s;
    }
    #rightBar_optionsDiv > .more > div {
        display: none;
    }
    #rightBar_optionsDiv > .more.expanded > div {
        display: initial;
    }
    #rightBar_optionsDiv > .more.expanded > a {
        background-color: var(--color-orange-1);
        color: white;
    }
    #rightBar_optionsDiv > .more.expanded img {
        transform: rotate(90deg);
    }
    #rightBar_optionsDiv a:hover {
        background-color: var(--color-orange-1);
        color: white;
    }
    #rightBar_notificationsDiv {
        /* height: 100%; */
        color: white;
    }
    #rightBar_notificationsDiv .sectionTitle {
        font-size: 1.5rem;
        margin: 1rem 0px 0px 1rem;
        color: var(--color-black-2);
        font-weight: bold;
    }
    #rightBar_notificationsDiv .notification {
        height: 5rem;
        display: flex;
        align-items: center;
        text-decoration: none;
        color: white;
        padding: 0px 0px 0px 1rem;
        font-size: 0.8em;
        margin: 0.2rem 0px 0px 0px;
    }
    #rightBar_notificationsDiv .notification.new {
        background-color: #0482FF;
        box-shadow: 0px 0px 0.4rem 0.1rem #0482FF;
    }
    #rightBar_notificationsDiv .notification .title {
        color: var(--color-orange-2);
    }
    #rightBar_notificationsDiv .notification:hover:not(.new) {
        background-color: var(--color-orange-1);
    }
    #rightBar_notificationsDiv .notification:hover:not(.new) .title {
        color: var(--color-black-1);
    }
    #rightBar_history .title {
        color: var(--color-orange-2);
    }
    #rightBar_history .historyItem {
        background-color: var(--color-black-1);
        font-size: 0.8rem;
        padding: 0.6rem;
        display: block;
        color: white;
        text-decoration: none;
    }
    #rightBar_history .historyItem:nth-child(2n) {
        background-color: #262a35;
    }
    .authPadded[data-is-auth="1"] {
        padding-top: min(32px,2rem);
    }

    CSS];
}

function getHomeMainDiv() {
    global $isAuth;
    return ['html' => <<<HTML
    <div id="mainDiv_home" class="authPadded" data-is-auth="$isAuth">
        <p></p>
    </div>

    HTML,
    'css' => <<<CSS
    #mainDiv_home {
        background: var(--bg-gradient-1);
        min-height: 100vh;
    }

    CSS];
}

function getForumMainElem() {
    global $isAuth,$root,$res;
    return ['html' => <<<HTML
    <div id="mainDiv_forum" class="authPadded" data-is-auth="$isAuth">
        <div id="forum_banner">
            <img src="$res/design/banners/v668.png" />
        </div>

        <div id="forum_content">
            <div id="forumL">
                <div class="forum_mainBar">
                    <div class="forum_mainBar_sub1"><p>Asile Intéressant</p></div>
                    <div class="forum_mainBar_sub2">
                        <div>
                            <button class="searchLoader button1" type="button"><img src="{$res}/icons/search.png"/></button><!--
                            --><button class="refreshThreads button1" type="button"><img src="{$res}/icons/refresh.png"/></button><!--
                            --><button class="newThreadLoader button1" type="button"><img src="{$res}/icons/edit.png"/>Créer un topic</button>
                        </div>
                    </div>
                </div>
                <table id="forum_threads">
                    <thead>
                        <tr>
                            <td></td>
                            <td>Topics</td>
                            <td>Rép.</td>
                        </tr>
                    </thead>
                    <tbody>
                        
                    </tbody>
                </table>
                <div class="forum_footer">
                    <div class="paginationDiv">
                        <div>
                            <button class="button1 first" type="button"><img src="{$res}/icons/first.png"/></button><!--
                            --><button class="button1 left" type="button"><img src="{$res}/icons/left.png"/></button>
                        </div>
                        <div>
                            <button class="button1 pagination_details" type="button">
                                <p>Page <span class="nPage">?</span> <span class="maxPages">/ <span class="maxPages">?<span class="nMaxPages"></span></p>
                            </button>
                        </div>
                        <div>
                            <button class="button1 right" type="button"><img src="{$res}/icons/right.png"/></button><!--
                            --><button class="button1 last" ttype="button"><img src="{$res}/icons/last.png"/></button>
                        </div>
                    </div>
                </div>
            </div>
            <div id="forumR">
            </div>
        </div>
    </div>
    HTML,
    'js' => <<<JAVASCRIPT
    const forumR = document.querySelector('#forumR');
    const forumL = document.querySelector('#forumL');
    let mobileMode = false;
    let currThreadId = null;

    function loadThreads(first,last,after,before,skipPages) {
        if (skipPages == null) skipPages = 0;
        sendQuery(`query Forum(\$first:Int,\$last:Int,\$after:ID,\$before:ID,\$skipPages:Int) {
            forum {
                threads(first:\$first,after:\$after,before:\$before,last:\$last,sortBy:"lastUpdate",withPageCount:true,skipPages:\$skipPages,withLastPageSpecialBehavior:true) {
                    edges {
                        node {
                            id
                            dbId
                            authorId
                            title
                            tags
                            creationDate
                            lastUpdateDate
                            permission
                            isRead
                            comments(last:1) {
                                edges {
                                    node {
                                        number
                                        author {
                                            name
                                        }
                                    }
                                }
                            }
                        }
                        cursor
                    }
                    pageInfo {
                        __typename
                        hasNextPage
                        hasPreviousPage
                        startCursor
                        endCursor
                        pageCount
                        currPage
                    }
                }
            }
        }`,{first:first,last:last,after:after,before:before,skipPages:skipPages}).then((json) => {
            if (json?.data?.forum?.threads?.edges == null) { basicQueryResultCheck(); return; }
            const tBody = document.querySelector('#forum_threads tbody');
            const threads = json.data.forum.threads;
            tBody.innerHTML = '';
            const now = new Date();
            let lastDelimiter = '';
            for (const edge of threads.edges) {
                const comment = edge.node.comments.edges[0].node;
                const date = new Date(edge.node.lastUpdateDate+'Z');
                if (!isNaN(date.getTime())) {
                    const sDate = getDateAsString(date).slice(0,4).join(' ');

                    if (date.toString().substr(0,15) == now.toString().substr(0,15)) {
                        if (lastDelimiter != "Aujourd'hui") {
                            tBody.insertAdjacentHTML('beforeend',`<tr><td colspan="100" class="delimiter"><p>Aujourd'hui</p></td></tr>`);
                            lastDelimiter = "Aujourd'hui";
                        }
                    } else if (lastDelimiter != sDate) {
                        tBody.insertAdjacentHTML('beforeend',`<tr><td colspan="100" class="delimiter"><p>\${sDate}</p></td></tr>`);
                        lastDelimiter = sDate;
                    }
                } else if (lastDelimiter != 'Date inconnue') {
                    tBody.insertAdjacentHTML('beforeend',`<tr><td colspan="100" class="delimiter"><p>Date inconnue</p></td></tr>`);
                    lastDelimiter = 'Date inconnue';
                }
                const tr = stringToNodes(`<tr data-node-id="\${edge.node.id}" class="thread \${edge.node.isRead ? '' : 'new'}">
                    <td class="statusIcons"><a href="#" onclick ="return false;"><div><img class="selectArrow" src="{$res}/icons/selected.png"/></div></a></td>
                    <td class="title"><a href="#" onclick ="return false;"><p>\${edge.node.title}</p></a></td>
                    <td class="quickDetails"><a href="#" onclick ="return false;"><p class="nAnswers">\${comment.number}</p><p class="author">\${comment.author.name}</p></a></td>
                </tr>`)[0];
                tBody.insertAdjacentElement('beforeend',tr);
                for (const e of tr.querySelectorAll('a')) {
                    e.addEventListener('click',() => loadThread(edge.node.id,10,null,null,null,0,true,true));
                    e.href=`$root/forum/\${edge.node.dbId}`;
                }

                const nodeImgNew = stringToNodes('<img class="new" src="{$res}/icons/recent.png"/>')[0];
                tr.querySelector('.statusIcons a div').insertAdjacentElement('afterbegin',nodeImgNew);
                if (edge.node.isRead) nodeImgNew.style.display = 'none';
            }

            const eNPage = document.querySelector('#forumL .nPage');
            const eMaxPages = document.querySelector('#forumL .maxPages');
            eNPage.innerHTML = threads.pageInfo.currPage;
            eMaxPages.innerHTML = `/ <span class="nMaxPages">\${threads.pageInfo.pageCount}</span>`;
            document.querySelector('#forumL .forum_footer .left').dataset.cursor = threads.pageInfo.startCursor;
            document.querySelector('#forumL .forum_footer .right').dataset.cursor = threads.pageInfo.endCursor;
            
            if (!mobileMode) highlightThread(currThreadId);
        });
    }
    function loadThread(threadId,first,last,after,before,skipPages=0,pushState=false,toFirstUnreadComment=false) {
        sendQuery(`query (\$threadId:ID!,\$first:Int,\$last:Int,\$after:ID,\$before:ID,\$skipPages:Int!,\$toFirstUnreadComment:Boolean!) {
            viewer {
                dbId
                titles
            }
            node(id:\$threadId) {
                __typename
                id
                ... on Thread {
                    dbId
                    title
                    followingIds
                    canRemove
                    kubedBy {
                        dbId
                        name
                    }
                    comments(first:\$first,after:\$after,before:\$before,last:\$last,skipPages:\$skipPages,
                        withPageCount:true,withLastPageSpecialBehavior:true,toFirstUnreadComment:\$toFirstUnreadComment) {
                        edges {
                            node {
                                id
                                threadId
                                number
                                creationDate
                                lastEditionDate
                                content
                                isRead
                                canEdit
                                canRemove
                                canOctohit
                                totalOctohitAmount
                                kubedBy {
                                    dbId
                                    name
                                }
                                octohits {
                                    amount
                                    user {
                                        dbId
                                        name
                                    }
                                }
                                author {
                                    id
                                    dbId
                                    name
                                    avatarURL
                                    stats {
                                        nAllThreads
                                        nAllComments
                                    }
                                }
                            }
                            cursor
                        }
                        pageInfo {
                            __typename
                            hasNextPage
                            hasPreviousPage
                            startCursor
                            endCursor
                            pageCount
                            currPage
                        }
                    }
                }
            }
        }`,{threadId:threadId,first:first,last:last,after:after,before:before,skipPages:skipPages,toFirstUnreadComment:toFirstUnreadComment}).then((json) => {
            if (json?.data?.node?.comments?.edges == null) { basicQueryResultCheck(); return; }
            currThreadId = threadId;
            highlightThread(currThreadId);
            const threadDbId = json.data.node.comments.edges[0].node.threadId;
            const viewerId = json.data.viewer.dbId;
            const viewer = json.data.viewer;
            const viewerIsAdmin = viewer.titles.includes('Administrator');

            forumR.innerHTML = '';
            if (mobileMode) { forumL.style.display = 'none'; forumR.style.display = ''; }
            if (forumR.querySelector('.forum_mainBar') == null) {
                const e = stringToNodes(`<div class="forum_mainBar">
                    <div class="forum_mainBar_sub1"><p>\${json.data.node.title}</p></div>
                    <div class="forum_mainBar_sub2">
                        <div class="actions"></div>
                    </div>
                </div>
                <div class="subheader">
                    <div class="infos1"></div>
                    <div class="infos2 hide">
                        <div class="main"></div>
                        <p><a class="infos2Msg" href="#" onclick="return false;">Plus d'informations...</a></p>
                    </div>
                </div>
                <div id="forum_comments"></div>
                <div class="forum_footer">
                    <div class="actions"></div>
                </div>`);
                for (const node of e) forumR.insertAdjacentElement('beforeend',node);

                let a = [];
                a[0] = forumR.querySelector('.forum_mainBar_sub2');
                a[1] = forumR.querySelector('.forum_footer');
                for (const cont of a) {
                    const paginationDiv = setupPagInput(null,10,() => loadThread(threadId,10),() => loadThread(threadId,null,10),
                        (n,cursor,skipPages) => loadThread(threadId,null,n,null,cursor,skipPages),
                        (n,cursor,skipPages) => loadThread(threadId,n,null,cursor,null,skipPages)
                    );
                    cont.insertAdjacentElement('beforeend', paginationDiv);
                }
            }

            const kubersIds = []; for (const o of json.data.node.kubedBy) kubersIds.push(o.dbId);
            const eInfos1 = forumR.querySelector('.subheader .infos1');
            const eInfos2 = forumR.querySelector('.subheader .infos2');
            const eInfos2Main = eInfos2.querySelector('.main');

            const kubeDiv = getKubeDiv(async () => sendQuery(`mutation KubeThread (\$threadId:Int!) {
                f:forum_kubeThread(threadId:\$threadId) {
                    success
                    resultCode
                    resultMessage
                    thread {
                        kubedBy {
                            name
                        }
                    }
                }
            }`,{threadId:threadDbId}).then((json) => {
                if (!basicQueryResultCheck(json?.data?.f)) return null;

                reloadInfos2(json.data.f.thread);
                return {amount:json.data.f.thread.kubedBy.length};
            }), async () => sendQuery(`mutation UnkubeThread (\$threadId:Int!) {
                f:forum_unkubeThread(threadId:\$threadId) {
                    success
                    resultCode
                    resultMessage
                    thread {
                        kubedBy {
                            name
                        }
                    }
                }
            }`,{threadId:threadDbId}).then((json) => {
                if (!basicQueryResultCheck(json?.data?.f)) return;

                reloadInfos2(json.data.f.thread);
                return {amount:json.data.f.thread.kubedBy.length};
            }));
            kubeDiv.set(kubersIds.length,kubersIds.includes(viewerId));
            eInfos1.insertAdjacentElement('beforeend',kubeDiv);

            function reloadInfos2(threadData) {
                if (threadData == null) return;
                const kubersName = []; for (const o of threadData.kubedBy) kubersName.push(o.name);
                const sUsers = kubersName.length == 0 ? '[Aucun]'  : kubersName.join(', ');
                const n = stringToNodes(`<p>Kubeurs : \${sUsers}</p>`)[0];
                eInfos2Main.innerHTML = '';
                eInfos2Main.insertAdjacentElement('beforeend',n);
            }
            reloadInfos2(json.data.node);

            eInfos2.querySelector('.infos2Msg').addEventListener('click',(e) => {
                if (eInfos2.classList.contains('hide')) {
                    e.target.innerHTML = "Moins d'informations...";
                    eInfos2.classList.remove('hide');
                } else {
                    e.target.innerHTML = "Plus d'informations...";
                    eInfos2.classList.add('hide');
                }
            });

            const eComments = document.querySelector('#forum_comments');
            const comments = json.data.node.comments;
            const autoMarkPagesAsRead = localGet('settings_forum_autoMarkPagesAsRead') === 'true';
            const eUnreadComments = [];
            const unreadCommentsNumbers = [];
            eComments.innerHTML = '';
            currThreadId = threadId;
            for (const comment of comments.edges) {
                const date = new Date(comment.node.creationDate+'Z');
                const commentNode = stringToNodes(`<div class="comment\${comment.node.isRead ? '' : ' new'}">
                    <div class="header">
                        <div class="avatarDiv">
                            <img class="avatar" src="\${comment.node.author.avatarURL}" />
                        </div>
                        <p class="name">\${comment.node.author.name}</p>
                        <p class="date" title="\${date.toString()}">\${getDateAsString2(date)}</p>
                        <p class="stats">Topics : \${comment.node.author.stats.nAllThreads} · Commentaires : \${comment.node.author.stats.nAllComments}</p>    
                   </div>
                    <div class="body">
                        <div class="main"></div>
                        <div class="footer"><p class="infos"></p><p class="commActions"></p></div>
                        <div class="hiddenFooter hidden" style="display:none;">
                            <div class="main"></div>
                            <p><a class="hiddenFooterMsg" href="#" onclick="return false;">Plus d'informations...</a></p>
                        </div>
                    </div>
                </div>`)[0];
                const commNodeMain = commentNode.querySelector('.body > .main');
                const commKubers = comment.node.kubedBy;
                const commOctohits = comment.node.octohits;
                if (!comment.node.isRead) {
                    eUnreadComments.push(commentNode);
                    unreadCommentsNumbers.push(comment.node.number);
                }

                // Footer
                const footerInfos = commentNode.querySelector('.footer p.infos');
                let aFooterInfos = [];
                if (comment.node.lastEditionDate != null) {
                    const date = new Date(comment.node.lastEditionDate+'Z');
                    aFooterInfos.push(`Dernière édition : \${getDateAsString2(date)}`);
                    const nodeDate = commentNode.querySelector('.date');
                    nodeDate.title = nodeDate.title + `\nEdit: \${date.toString()}`;
                }
                if (aFooterInfos.length > 0) footerInfos.innerHTML = aFooterInfos.join('<br/>') + '<br/>';
                
                const footerP = commentNode.querySelector('.footer p.commActions');
                const nodeHidden = stringToNodes('<span class="hidden" style="display:none;"></span>')[0];
                const hiddenFooter = commentNode.querySelector('.hiddenFooter');
                const aFooter = [];
                const aHidden = [];

                let hoverForReadCD = 0;

                // Footer: Kubes
                const kubersIds = []; for (const o of comment.node.kubedBy) kubersIds.push(o.dbId);
                const kubeDiv = getKubeDiv(async () => sendQuery(`mutation KubeComment (\$threadId:Int!, \$commNumber:Int!) {
                    f:forum_kubeComment(threadId:\$threadId,commNumber:\$commNumber) {
                        success
                        resultCode
                        resultMessage
                        comment {
                            kubedBy {
                                dbId
                                name
                            }
                        }
                    }
                }`,{threadId:threadDbId,commNumber:comment.node.number}).then((json) => {
                    if (!basicQueryResultCheck(json?.data?.f)) return null;
                    return {amount:json.data.f.comment.kubedBy.length};
                }), async () => sendQuery(`mutation UnkubeComment (\$threadId:Int!,\$commNumber:Int!) {
                    f:forum_unkubeComment(threadId:\$threadId,commNumber:\$commNumber) {
                        success
                        resultCode
                        resultMessage
                        comment {
                            kubedBy {
                                dbId
                                name
                            }
                        }
                    }
                }`,{threadId:threadDbId,commNumber:comment.node.number}).then((json) => {
                    if (!basicQueryResultCheck(json?.data?.f)) return;
                    return {amount:json.data.f.comment.kubedBy.length};
                }));
                kubeDiv.set(kubersIds.length,kubersIds.includes(viewerId));
                aFooter.push(kubeDiv);

                // Footer: Octohits
                let hits = 0;
                let hitCd = null;
                let tlPScale, animPValue = null;
                let animBut = null; 
                const octohitDiv = getOctohitDiv(async () => {
                    const img = octohitDiv.querySelector("img");
                    const p = octohitDiv.querySelector("p");

                    if (hitCd != null) clearTimeout(hitCd);
                    hits++;

                    // anim button
                    if (animBut != null) animBut.kill();
                    if (hits < 3) animBut = gsap.fromTo(img,{scale:(1 + 0.2 * hits)},{delay:0.25,scale:1,duration:0.5,ease:'linear'});
                    else img.style.transform = '';

                    if (hits >= 3) {
                        hits = 0;
                        return sendQuery(`mutation OctohitComment (\$threadId:Int!, \$commNumber:Int!) {
                            f:forum_octohitComment(threadId:\$threadId,commNumber:\$commNumber) {
                                success
                                resultCode
                                resultMessage
                                octohit {
                                    amount
                                }
                                comment {
                                    canOctohit
                                    totalOctohitAmount
                                }
                            }
                        }`,{threadId:threadDbId,commNumber:comment.node.number}).then((json) => {
                            if (!basicQueryResultCheck(json?.data?.f)) return null;
                            let comment = json.data.f.comment;

                            // anim p value
                            p.style.userSelect = p.style.pointerEvents = 'none';
                            const twObj = {value:parseInt(p.innerText)};
                            if (animPValue != null) animPValue.kill();
                            animPValue = gsap.to(twObj, {duration:1, value:comment.totalOctohitAmount, ease:'linear', onUpdate:() => p.innerHTML = parseInt(twObj.value)});

                            // anim p scale
                            if (tlPScale == null) tlPScale = gsap.timeline({paused:false,onComplete:() => {p.style.userSelect = p.style.pointerEvents = '';}})
                                .to(p,{duration:0.1,scale:2.5,color:'red'},0)
                                .to(p,{scale:1,color:'darkred'},'>1.5');
                            else tlPScale.tweenTo(0.1,{duration:0.1}).then(() => tlPScale.resume());

                            // anim p diff
                            const e = stringToNodes(`<p>+\${json.data.f.octohit.amount}</p>`)[0];
                            octohitDiv.querySelector('.diffsDiv').insertAdjacentElement('beforeend',e);
                            gsap.to(e,{opacity:0,delay:3}).then(() => e.remove());


                            return {amount:comment.totalOctohitAmount,lit:!comment.canOctohit};
                        });
                    } else hitCd = setTimeout(() => { hits = 0; }, 500);
                });
                octohitDiv.querySelector('.octohitDiv_mid').insertAdjacentHTML('beforeend','<div class="diffsDiv"></div>');
                octohitDiv.set(comment.node.totalOctohitAmount,!comment.node.canOctohit);
                aFooter.push(octohitDiv);

                // Footer: Cite
                const nodeCite = stringToNodes('<a class="cite" href="#" onclick="return false;">Citer</a>')[0];
                nodeCite.addEventListener('click',() => {
                    const replyFormDiv = forumR.querySelector('.replyFormDiv');
                    if (replyFormDiv.classList.contains('hide')) replyFormDiv.classList.remove('hide');

                    const citedUsername = commentNode.querySelector('.header .name').innerText;
                    const sel = getSelection();
                    const s = (sel?.toString() != null && commentNode.querySelector('.body .main').contains(sel.anchorNode)) ?
                        `[cite=\${citedUsername}]\${sel.toString()}[/cite]`
                        : `[cite=\${citedUsername}]\${contentToText(commNodeMain.children)}[/cite]`;
                    
                    const textarea = replyFormDiv.querySelector('textarea');
                    const start = textarea.selectionStart;
                    textarea.value = textarea.value.slice(0,start) + s + textarea.value.slice(start);
                    textarea.selectionStart = start+s.length;
                    textarea.dispatchEvent(new Event('input'));
                    textarea.focus();
                });
                aFooter.push(nodeCite);

                // Footer: MarkAsNotRead
                const nodeMarkAsNotRead = stringToNodes('<a class="cite" href="#" onclick="return false;">Marquer en non lu</a>')[0];
                let to_notRead = null;
                nodeMarkAsNotRead.addEventListener('click',() => {
                    sendQuery(`mutation MarkCommentsAsNotRead(\$threadId:Int!,\$commNumbers:[Int!]!) {
                        f:forumThread_markCommentsAsNotRead(threadId:\$threadId,commentNumbers:\$commNumbers) {
                            success
                            resultCode
                            resultMessage
                        }
                    }`,{threadId:threadDbId,commNumbers:[comment.node.number]}).then((json) => {
                        if (!basicQueryResultCheck(json?.data?.f)) return;
                        if (to_notRead != null) clearTimeout(to_notRead);
                        commentNode.classList.add('new');
                        const e = document.querySelector(`#forum_threads .thread[data-node-id="\${threadId}"]`);
                        if (e != null) { e.classList.add('new'); e.querySelector('.statusIcons .new').style.display = ''; }

                        hoverForReadCD = 1;
                        
                        to_notRead = setTimeout(() => { hoverForReadCD = 0; }, 1000);
                    });
                });
                aHidden.push(nodeMarkAsNotRead);

                // Footer: Edit
                if (comment.node.canEdit) {
                    const nodeEdit = stringToNodes('<a class="edit" href="#" onclick="return false;">Éditer</a>')[0];
                    nodeEdit.addEventListener('click',() => {
                        const replyFormId = `edit_\${comment.node.id}`;
                        const titleId = replyFormId+'_title';
                        if (commentNode.classList.contains('selected')) { loadReplyForm(replyFormId); commentNode.classList.remove('selected'); return; }

                        commentNode.classList.add('selected');
                        if (replyFormSetups.get(replyFormId) == null) {
                            addReplyFormSetup(replyFormId,async (div) => {
                                if (comment.node.number == 0) {
                                    const nodeTitle = stringToNodes(`<div class="title">
                                        <label for="editThread_title">Titre : </label><input id="editThread_title" class="inputText1" type="text" name="title"/>
                                    </div>`)[0];
                                    div.querySelector('.replyForm').insertAdjacentElement('afterbegin',nodeTitle);
                                    const input = nodeTitle.querySelector('#editThread_title');
                                    input.value = sessionGet('title') ?? json.data.node.title;
                                    input.addEventListener('input',() => sessionSet('title',input.value));
                                }
                                div.querySelector('.replyForm').insertAdjacentHTML('afterbegin','<p class="formTitle">Édition de commentaire</p>')
                                setupReplyForm(div,async (e) => {
                                    e.preventDefault();
                                    const submitButton = e.target.querySelector('input[type="submit"]');
                                    if (submitButton.disabled === true) return;
                                    submitButton.disabled = true;

                                    const data = new FormData(e.target);
                                    const title = data.get('title');
                                    return await sendQuery(`mutation ForumEditComment(\$threadId:Int!,\$commNumber:Int!,\$title:String,\$content:String!) {
                                        f:forumThread_editComment(threadId:\$threadId,commentNumber:\$commNumber,title:\$title,content:\$content) {
                                            __typename
                                            success
                                            resultCode
                                            resultMessage
                                        }
                                    }`,{threadId:threadDbId,commNumber:comment.node.number,title:title,content:data.get("msg")}).then((json) => {
                                        if (!basicQueryResultCheck(json?.data?.f)) { submitButton.disabled = false; return false; }

                                        if (comment.node.number == 0) sessionRem(titleId);
                                        loadThread(currThreadId,10,null,null,null,0,false,true);
                                        return true;
                                    });
                                },replyFormId);
                            });
                        }
                        loadReplyForm(replyFormId);
                        const replyForm = document.querySelector('#forumR .replyForm');
                        const textarea = replyForm.querySelector('textarea');
                        if (textarea.value == '') textarea.value = contentToText(commentNode.querySelector('.body .main').children);
                        if (comment.node.number == 0) {
                            const title = replyForm.querySelector('.title input');
                            if (title.value == '') title.value = json.data.node.title;
                        }
                        textarea.dispatchEvent(new Event('input'));
                    });
                    if (!viewerIsAdmin) aFooter.push(nodeEdit);
                    else aHidden.push(nodeEdit);
                }
                
                // Footer: Remove
                if (comment.node.canRemove || json.data.node.canRemove) {
                    const nodeDel = stringToNodes('<a class="delete" href="#" onclick="return false;">Supprimer</a>')[0];
                    nodeDel.addEventListener('click',() => {
                        const e = stringToNodes(`<div id="askDelete" class="popupContainer" style="padding: 1rem;display: flex;gap: 1rem;">
                            <input id="askDelete_cancel" type="button" value="Retour"/>
                            <input id="askDelete_delete" type="button" value="Supprimer"/>
                        </div>`)[0];
                        e.querySelector('#askDelete_cancel').addEventListener('click',() => { popupDiv.close(); e.remove(); });
                        const delBut = e.querySelector('#askDelete_delete');
                        delBut.addEventListener('click',() => {
                            if (comment.node.number == 0) {
                                sendQuery(`mutation RemoveThread (\$threadId:Int!) {
                                    f:forum_removeThread(threadId:\$threadId) {
                                        __typename
                                        success
                                        resultCode
                                        resultMessage
                                    }
                                }`,{threadId:threadDbId}).then((json) => {
                                    if (!basicQueryResultCheck(json?.data?.f)) return;
                                    location.href = "$root/forum";
                                });
                            } else {
                                sendQuery(`mutation ForumRemoveComment (\$threadId:Int!, \$commNumber:Int!) {
                                        f:forumThread_removeComment(threadId:\$threadId,commentNumber:\$commNumber) {
                                            __typename
                                            success
                                            resultCode
                                            resultMessage
                                        }
                                    }
                                `,{threadId:threadDbId,commNumber:comment.node.number}).then((json) => {
                                    if(!basicQueryResultCheck(json?.data?.f)) return;
                                    loadThread(currThreadId,10,null,null,null,0,false,true);
                                });
                            }
                            popupDiv.close();
                            e.remove();
                        });
                        popupDiv.insertAdjacentElement('beforeend',e);
                        popupDiv.openTo('#askDelete');
                    });
                    if (!viewerIsAdmin) aFooter.push(nodeDel);
                    else aHidden.push(nodeDel);
                }

                // Footer: More
                const nodeMore = stringToNodes('<span><p> - </p><a class="more" href="#" onclick="return false;">Plus...</a></span>')[0];
                const nodeLess = stringToNodes('<a class="more" href="#" onclick="return false;">Moins...</a>')[0];
                nodeMore.addEventListener('click',() => {
                    nodeHidden.style.display = hiddenFooter.style.display = '';
                    nodeMore.remove();
                });
                nodeLess.addEventListener('click',() => {
                    nodeHidden.style.display = hiddenFooter.style.display = 'none';
                    footerP.insertAdjacentElement('beforeend',nodeMore);
                });
                aHidden.push(nodeLess);

                for (const n of aFooter) {
                    if (n != aFooter[0]) footerP.insertAdjacentHTML('beforeend', '<p> - </p>');
                    footerP.insertAdjacentElement('beforeend',n);
                }
                for (const n of aHidden) {
                    nodeHidden.insertAdjacentHTML('beforeend','<p> - </p>');
                    nodeHidden.insertAdjacentElement('beforeend',n);
                }
                footerP.insertAdjacentElement('beforeend',nodeHidden);
                footerP.insertAdjacentElement('beforeend',nodeMore);

                // Footer: More infos
                const hiddenFooterMsg = hiddenFooter.querySelector('.hiddenFooterMsg');
                const hiddenFooterMain = hiddenFooter.querySelector('.main');
                hiddenFooterMsg.addEventListener('click', () => {
                    if (hiddenFooter.classList.contains('hidden')) {
                        hiddenFooterMsg.innerHTML = "Moins d'informations...";
                        hiddenFooter.classList.remove('hidden');
                    } else {
                        hiddenFooterMsg.innerHTML = "Plus d'informations...";
                        hiddenFooter.classList.add('hidden');
                    }
                });

                hiddenFooterMain.innerHTML = '';
                const kubersName = []; for (const o of commKubers) kubersName.push(o.name);
                const sUsers = kubersName.length == 0 ? '[Aucun]'  : kubersName.join(', ');
                hiddenFooterMain.insertAdjacentElement('beforeend',stringToNodes(`<p>Kubeurs : \${sUsers}</p>`)[0]);

                const oHitters = {}; for (const o of commOctohits) {
                    oHitters[o.user.dbId] ??= {totalAmount:0, times:0, hits:[], username:o.user.name};
                    oHitters[o.user.dbId].totalAmount += o.amount;
                    oHitters[o.user.dbId].hits.push(o.amount);
                    oHitters[o.user.dbId].times++;
                }
                const oSortedHitters = Object.values(oHitters);
                oSortedHitters.sort((a,b) => {
                    if (a.totalAmount > b.totalAmount) return -1;
                    else if (a.totalAmount < b.totalAmount) return 1;
                    return 0;
                });
                let sHitters = '<ul>';
                for (const o of oSortedHitters) {
                    sHitters += '<li>' + o.username + ' : ';
                    if (o.hits.length > 1) {
                        let sAdds = '';
                        for (const v of o.hits) {
                            if (sAdds != '') sAdds += ' + ';
                            sAdds += v;
                        }
                        sHitters += sAdds + ' = ' + o.totalAmount + '</li>';
                    } else {
                        sHitters += o.totalAmount + '</li>';
                    }
                }
                sHitters += '</ul>';
                hiddenFooterMain.insertAdjacentElement('beforeend',stringToNodes(`<p>Frappeurs : </p>`)[0]);
                hiddenFooterMain.insertAdjacentElement('beforeend',stringToNodes(`\${sHitters}`)[0]);

                processComment(commNodeMain,stringToNodes(comment.node.content));

                // Events
                let b = false;
                if (!autoMarkPagesAsRead) commentNode.querySelector('.body').addEventListener('mouseover',() => {
                    if (!commentNode.classList.contains('new') || b || hoverForReadCD > 0) return;
                    b = true;
                    sendQuery(`mutation MarkCommentsAsRead (\$threadId:Int!, \$commNumbers:[Int!]!) {
                        f:forumThread_markCommentsAsRead(threadId:\$threadId,commentNumbers:\$commNumbers) {
                            __typename
                            success
                            resultCode
                            resultMessage
                        }
                    }`.trim(),{threadId:json.data.node.dbId,commNumbers:[comment.node.number]}).then((json) => {
                        if (!basicQueryResultCheck(json?.data?.f)) { b = false; return; }

                        commentNode.classList.remove('new');
                        sendQuery(`query (\$threadId:ID!) {
                            node(id:\$threadId) {
                                id
                                ... on Thread {
                                    isRead
                                }
                            }
                        }`,{threadId:threadId}).then((json) => {
                            if (json?.data?.node?.isRead == null) { basicQueryResultCheck(); return; }
                            if (json.data.node.isRead == false) return;
                            const e = document.querySelector(`#forum_threads .thread[data-node-id="\${threadId}"]`);
                            if (e != null) {
                                e.classList.remove('new');
                                e.querySelector('.statusIcons .new').style.display = 'none';
                            }
                        });

                        if (json.data.f.resultMessage == 'refresh') getRecentEvents();
                        b = false;
                    });
                });

                // Add element
                eComments.insertAdjacentElement('beforeend',commentNode);
            }
            if (autoMarkPagesAsRead && eUnreadComments.length > 0) {
                sendQuery(`mutation MarkCommentsAsRead (\$threadId:Int!, \$commNumbers:[Int!]!) {
                    f:forumThread_markCommentsAsRead(threadId:\$threadId,commentNumbers:\$commNumbers) {
                        __typename
                        success
                        resultCode
                        resultMessage
                    }
                }`.trim(),{threadId:json.data.node.dbId,commNumbers:unreadCommentsNumbers}).then((json) => {
                    if (!basicQueryResultCheck(json?.data?.f)) { b = false; return; }

                    // for (const comm of eUnreadComments) if (comm.classList.contains('new')) setTimeout(() => comm.classList.remove('new'), 10000);
                    sendQuery(`query (\$threadId:ID!) {
                        node(id:\$threadId) {
                            id
                            ... on Thread {
                                isRead
                            }
                        }
                    }`,{threadId:threadId}).then((json) => {
                        if (json?.data?.node?.isRead == null) { basicQueryResultCheck(); return; }
                        if (json.data.node.isRead == false) return;
                        const e = document.querySelector(`#forum_threads .thread[data-node-id="\${threadId}"]`);
                        if (e != null) {
                            e.classList.remove('new');
                            const eNew = e.querySelector('.statusIcons .new');
                            if (eNew != null) eNew.style.display = 'none';
                        }
                    });

                    if (json.data.f.resultMessage == 'refresh') getRecentEvents();
                });
            }

            const n = first ?? last;
            const forumRPaginations = document.querySelectorAll('#forumR .paginationDiv');
            if (comments.pageInfo.pageCount == 1) for (const e of forumRPaginations) e.style.display = 'none';
            else {
                for (const e of forumRPaginations) {
                    e.style.display = '';
                    e.querySelector('.nPage').innerHTML = comments.pageInfo.currPage;
                    e.querySelector('.nMaxPages').innerHTML = comments.pageInfo.pageCount;

                    const left = e.querySelector('.left'); 
                    const right = e.querySelector('.right');
                    left.dataset.cursor = comments.pageInfo.startCursor;
                    right.dataset.cursor = comments.pageInfo.endCursor;
                }
            }

            const replyFormDiv = getNewReplyForm();
            const replyFormSetups = new Map();
            let currReplyFormLoaded = '';
            forumR.insertAdjacentElement('beforeend',replyFormDiv);
            function addReplyFormSetup(name, f) {
                replyFormSetups.set(name, () => f(replyFormDiv));
            }
            function loadReplyForm(name) {
                if (currReplyFormLoaded == name || replyFormSetups.get(name) == null) {
                    if (replyFormDiv.classList.contains('hide')) {
                        replyFormDiv.classList.remove('hide');
                        replyFormDiv.querySelector('textarea').focus();
                    } else {
                        replyFormDiv.classList.add('hide');
                        replyFormDiv.querySelector('textarea').blur();
                    }
                    return;
                }

                replyFormDiv.innerHTML = '';
                for (const e of Array.from(getNewReplyForm().children)) replyFormDiv.insertAdjacentElement('beforeend',e);

                currReplyFormLoaded = name;
                replyFormDiv.classList.remove('hide');
                replyFormSetups.get(name)();
            }

            addReplyFormSetup('reply',(div) => {
                setupReplyForm(div,async (e,moreData) => {
                    e.preventDefault();

                    const data = new FormData(e.target);
                    const submitButton = e.target.querySelector('input[type="submit"]');
                    if (submitButton.disabled === true) return;
                    submitButton.disabled = true;

                    return await sendQuery(`mutation ForumAddComment(\$threadId:Int!,\$msg:String!) {
                        f:forumThread_addComment(threadId:\$threadId,content:\$msg) {
                            __typename
                            success
                            resultCode
                            resultMessage
                        }
                    }`,{threadId:json.data.node.dbId,msg:data.get('msg')},null,null,null,moreData).then((json) => {
                        if (!basicQueryResultCheck(json?.data?.f,true)) { submitButton.disabled = false; return false; }
                        loadThread(threadId,0,10);
                        loadThreads(20);
                        return true;
                    });
                },'forum_replyText');
            });
            loadReplyForm('reply');
            replyFormDiv.classList.add('hide');

            const actionsCont = document.querySelectorAll('#forumR .actions');
            for (const cont of actionsCont) {
                cont.innerHTML = '';
                const back = stringToNodes('<button class="button1 mobile back" type="button"><img src="{$res}/icons/back.png"/></button>')[0];
                cont.insertAdjacentElement('beforeend',back);
                back.addEventListener('click', () => {
                    loadPage("$root/forum",StateAction.PushState);
                    document.querySelector('.refreshThreads').click();
                });
                back.style.display = mobileMode ? '' : 'none';
                const reply = stringToNodes('<button class="button1 reply" type="button"><img src="{$res}/icons/edit.png"/>Répondre</button>')[0];
                cont.insertAdjacentElement('beforeend',reply);
                reply.addEventListener('click', () => {
                    for (const e of document.querySelectorAll('#forum_comments .comment.selected')) e.classList.remove("selected");
                    loadReplyForm('reply');
                });
            }
            function getFollowButton() {
                const e = stringToNodes('<button class="button1 follow" type="button"><p><img src="{$res}/icons/mail.png" />Suivre</p></button>')[0];
                e.addEventListener('click',() => {
                    const buttons = document.querySelectorAll('#forumR .actions .follow');
                    for (const e of buttons) e.disabled = true;
                    sendQuery(`mutation Follow(\$threadId:Int!) {
                        f:forumThread_follow(threadId:\$threadId) {
                            __typename
                            success
                            resultCode
                            resultMessage
                        }
                    }`,{threadId:json.data.node.dbId},null,'Follow').then((json) => {
                        if (!basicQueryResultCheck(json?.data?.f,true)) {
                            for (const e of buttons) e.disabled = false;
                            return;
                        }

                        for (const e of buttons) e.replaceWith(getUnfollowButton());
                    });
                });
                return e;
            }
            function getUnfollowButton() {
                const e = stringToNodes('<button class="button1 follow" type="button"><p><img src="{$res}/icons/remove.png" />Ne plus suivre</p></button>')[0];
                e.addEventListener('click',() => {
                    const buttons = document.querySelectorAll('#forumR .actions .follow');
                    for (const e of buttons) e.disabled = true;
                    sendQuery(`mutation Unfollow(\$threadId:Int!) {
                        f:forumThread_unfollow(threadId:\$threadId) {
                            __typename
                            success
                            resultCode
                            resultMessage
                        }
                    }`,{threadId:json.data.node.dbId},null,'Unfollow').then((json) => {
                        if (!basicQueryResultCheck(json?.data?.f,true)) {
                            for (const e of buttons) e.disabled = false;
                            return;
                        }

                        for (const e of buttons) e.replaceWith(getFollowButton());
                    });
                });
                return e;
            }
            if (json.data.node.followingIds.includes(json.data.viewer.dbId)) {
                for (const cont of document.querySelectorAll('#forumR .actions'))
                    cont.insertAdjacentElement('beforeend',getUnfollowButton());                 
            } else {
                for (const cont of document.querySelectorAll('#forumR .actions'))
                    cont.insertAdjacentElement('beforeend',getFollowButton());
            }

            if (pushState) {
                const url = `$root/forum/\${json.data.node.dbId}`;
                history.pushState({pageUrl:url}, "", url);
            }
        });
    }
    function highlightThread(threadId) {
        for (const e of forumL.querySelectorAll(`#forum_threads tbody tr`)) e.dataset.selected = false;
        if (threadId == null) return;
        const e = forumL.querySelector(`tr[data-node-id="\${threadId}"]`);
        if (e == null) return;
        e.dataset.selected = true;
    }
    function setupPagInput(eCont,itemsPerPage,firstPage,lastPage,before,after) {
        if (eCont == null) {
            eCont = stringToNodes(`<div class="paginationDiv">
                <div>
                    <button class="button1 first" type="button"><img src="{$res}/icons/first.png"/></button><!--
                    --><button class="button1 left" type="button"><img src="{$res}/icons/left.png"/></button>
                </div>
                <div>
                    <button class="button1 pagination_details" type="button">
                        <p>Page <span class="nPage">?</span> <span class="maxPages">/ <span class="nMaxPages">?</span></span></p>
                    </button>
                </div>
                <div>
                    <button class="button1 right" type="button"><img src="{$res}/icons/right.png"/></button><!--
                    --><button class="button1 last" type="button"><img src="{$res}/icons/last.png"/></button>
                </div>
            </div>`)[0];
        }

        const pagDetails = eCont.querySelector('.pagination_details');
        function getCurrPage() { return parseInt(pagDetails.querySelector('.nPage').innerHTML); }
        function getMaxPages() { return parseInt(pagDetails.querySelector('.nMaxPages').innerHTML); }

        const first = eCont.querySelector('.first');
        const left = eCont.querySelector('.left');
        const right = eCont.querySelector('.right');
        const last = eCont.querySelector('.last');
        first.addEventListener('click',firstPage);
        left.addEventListener('click',() => (getCurrPage() == 1 ? firstPage() : before(itemsPerPage,left.dataset.cursor,0)));
        right.addEventListener('click',() => (getCurrPage() == getMaxPages() ? lastPage() : after(itemsPerPage,right.dataset.cursor,0)));
        last.addEventListener('click',lastPage);
        let pagDetailsInput = null;
        pagDetails.addEventListener('click',() => {
            if (pagDetailsInput != null) return;
            pagDetailsInput = stringToNodes('<input type="text" />')[0];
            const eNPage = pagDetails.querySelector('.nPage');
            eNPage.style.display = 'none';
            eNPage.insertAdjacentElement('beforebegin',pagDetailsInput);
            pagDetailsInput.focus();

            let b = false;
            function go() {
                if (b) return;
                b = true;
                const v = parseInt(pagDetailsInput.value);
                const currPage = getCurrPage();
                const nMaxPages = getMaxPages();

                pagDetailsInput.remove();
                eNPage.style.display = '';
                pagDetailsInput = null;
                if (isNaN(v)) { b = false; return; }
                if (v <= 1) { firstPage(); b = false; return; }
                if (v >= nMaxPages) { lastPage(); b = false; return; }

                if (v >= currPage) after(itemsPerPage,right.dataset.cursor,v-currPage-1); 
                else if (v < currPage) before(itemsPerPage,left.dataset.cursor,currPage-v-1);
                b = false;
            }
            pagDetailsInput.addEventListener('blur',go);
            pagDetailsInput.addEventListener('keydown',(e) => (e.key == 'Enter' ? go() : null));
        });

        return eCont;
    }
    function loadNewThreadForm() {
        forumR.innerHTML = '';
        const e = stringToNodes(`
        <div class="forum_mainBar">
            <div class="forum_mainBar_sub1"><p>Nouveau topic</p></div>
            <div class="forum_mainBar_sub2">
                <div class="actions"></div>
            </div>
        </div>
        <div class="replyFormDiv">
            <a class="previewToggler" href="#" onclick="return false;">Masquer / afficher l'aperçu de votre message</a>
            <div class="preview">

            </div>
            <form class="replyForm">
                <div class="title">
                    <label for="newThread_title">Titre : </label><input id="newThread_title" class="inputText1" type="text" name="title" tabindex="1"/>
                </div>
                <div class="buttonBar">
                    <div>
                        <button class="button1 bold" type="button">G</button><!--
                        --><button class="button1 italic" type="button">I</button><!--
                        --><button class="button1 strike" type="button">Barré</button><!--
                        --><button class="button1 link" type="button">Lien</button><!--
                        --><button class="button1 cite" type="button">Citer</button><!--
                        --><button class="button1 spoil" type="button">Spoil</button><!--
                        --><button class="button1 rp" type="button">Roleplay</button><!--
                        --><button class="button1 code" type="button">Code</button>
                    </div>
                    <div>
                        <input type="file" style="display:none;" />
                        <button class="button1 file" type="button">Insérer un fichier</button>
                    </div>
                </div>
                <textarea name="msg" tabindex="2"></textarea>
                <div class="optDiv">
                    <label for="opt_specChar_\${newReplyFormC}">Coller échappe les caractères spéciaux : </label><input id="opt_specChar_\${newReplyFormC}" class="opt_specChar" type="checkbox" />
                </div>
                <div class="emojisDiv">
                    <div class="emojisButtons"></div>
                    <div class="emojis"></div>
                </div>
                <input class="button2" type="submit" value="Envoyer"/>
            </form>
        </div>`.trim());
        for (const node of e) forumR.insertAdjacentElement('beforeend',node);
        if (mobileMode) { forumL.style.display = 'none'; forumR.style.display = ''; }

        const back = stringToNodes('<button class="button1 mobile back" type="button"><img src="{$res}/icons/back.png"/></button>')[0];
        forumR.querySelector('.forum_mainBar_sub2 .actions').insertAdjacentElement('beforeend',back);
        back.addEventListener('click', () => {
            if (!mobileMode) return;
            forumR.style.display = 'none';
            forumL.style.display = '';
        });
        back.style.display = mobileMode ? '' : 'none';

        setupReplyForm(forumR.querySelector('.replyFormDiv'), async (e,moreData) => {
            e.preventDefault();
            const data = new FormData(e.target);
            const submitButton = e.target.querySelector('input[type="submit"]');
            if (submitButton.disabled === true) return;
            submitButton.disabled = true;

            return await sendQuery(`mutation NewThread(\$title:String!,\$tags:[String!]!,\$msg:String!) {
                f:forum_newThread(title:\$title,tags:\$tags,content:\$msg) {
                    __typename
                    success
                    resultCode
                    resultMessage
                    thread {
                        __typename
                        id
                        dbId
                        followingIds
                    }
                }
            }`,{title:data.get('title'),tags:[],msg:data.get('msg')},null,null,null,moreData).then((json) => {
                if (json?.data?.f?.thread?.id == null) {
                    basicQueryResultCheck(null,true);
                    submitButton.disabled = false;
                    return false;
                }
                loadPage(`$root/forum/\${json.data.f.thread.dbId}`, StateAction.PushState);
                loadThreads(20);
                return true;
            });
        },'forum_newThreadText');
        forumR.querySelector('.replyForm textarea').focus();
    }
    function loadSearchForm() {
        forumR.innerHTML = '';
        const e = stringToNodes(`
        <datalist id="dl_userlist"></datalist>
        <div class="forum_mainBar">
            <div class="forum_mainBar_sub1"><p>Recherche</p></div>
            <div class="forum_mainBar_sub2">
                <div class="actions"></div>
            </div>
        </div>
        <form id="searchForm">
            <div class="parameters">
                <label for="searchForm_keywords">Mots clés :</label><input id="searchForm_keywords" class="inputText1" type="text" name="keywords" pattern="^[\\\\w\\\\+\\\\~\\\\-,\\\\s]+$"/>
                <label for="search_threadType">Type de topic :</label><div>
                    <input id="searchForm_threadType_standard" name="threadType" type="radio" value="Standard" checked="true"/><label for="searchForm_threadType_standard">SiteInteressant</label>
                    <input id="searchForm_threadType_twinoid" name="threadType" type="radio" value="Twinoid"/><label for="searchForm_threadType_twinoid">Twinoid</label>
                </div>
                <label for="searchForm_dateRange">Date :</label><div>
                    <input id="searchForm_fromDate" type="date" name="fromDate"/> -
                    <input id="searchForm_toDate" type="date" name="toDate"/>
                </div>
                <label for="searchForm_sortBy">Trier par :</label><div>
                    <input id="searchForm_sortByRelevance" name="sortBy" type="radio" value="ByRelevance" checked="true"/><label for="searchForm_sortByRelevance">Pertinence</label>
                    <input id="searchForm_sortByDate" name="sortBy" type="radio" value="ByDate"/><label for="searchForm_sortByDate">Date</label>
                </div>
                <label for="searchForm_author">Auteur :</label><input id="searchForm_author" name="author" type="text" list="dl_userlist"/>
            </div>
            <div class="buttons">
                <input class="button3" type="submit" value="Rechercher"/>
            </div>
        </form>
        <div class="pagDivDiv" style="display:none;"></div>
        <div id="searchFormResults"></div>
        <div class="pagDivDiv" style="display:none;"></div>
        `.trim());
        for (const node of e) forumR.insertAdjacentElement('beforeend',node);
        if (mobileMode) { forumL.style.display = 'none'; forumR.style.display = ''; }
        for (const cont of forumR.querySelectorAll('.actions')) {
            const back = stringToNodes('<button class="button1 mobile back" type="button"><img src="{$res}/icons/back.png"/></button>')[0];
            cont.insertAdjacentElement('beforeend',back);
            back.addEventListener('click', () => {
                if (!mobileMode) return;
                forumR.style.display = 'none';
                forumL.style.display = '';
            });
            back.style.display = mobileMode ? '' : 'none';
        }
        loadDatalist(false);

        document.querySelector('#searchForm_threadType_twinoid').addEventListener('change',(e) => {
            if (!e.target.checked) return;
            loadDatalist(true);
        });
        document.querySelector('#searchForm_threadType_standard').addEventListener('change',(e) => {
            if (!e.target.checked) return;
            loadDatalist(false);
        });

        const pagDivs = forumR.querySelectorAll('.pagDivDiv');
        for (const node of pagDivs) {
            node.insertAdjacentElement('beforeend',setupPagInput(null,10,
                () => loadSearchResults(10),
                () => loadSearchResults(0,10),
                (n,cursor,skipPages) => loadSearchResults(null,n,null,cursor,skipPages),
                (n,cursor,skipPages) => loadSearchResults(n,null,cursor,null,skipPages)
            )); 
        }
        
        const searchForm = forumR.querySelector('#searchForm');
        const searchFormResults = forumR.querySelector('#searchFormResults');
        const submitButton = forumR.querySelector('input[type="submit"]');
        let keywords, threadType, sortBy, startDate, endDate, userIds = '';
        searchForm.addEventListener('submit',async (e) => {
            e.preventDefault();
            if (submitButton.disabled === true) return;
            submitButton.disabled = true;

            const data = new FormData(e.target);
            keywords = data.get('keywords');
            threadType = data.get('threadType');
            sortBy = data.get('sortBy');
            startDate = data.get('fromDate') == '' ? null : data.get('fromDate');
            endDate = data.get('toDate') == '' ? null : data.get('toDate');
            
            let m = /^[^\(]+\((\d+)\)$/.exec(data.get('author'));
            userIds = m == null ? null : [parseInt(m[1])];

            await loadSearchResults(10);
            submitButton.disabled = false;
        });

        async function loadSearchResults(first,last,after,before,skipPages = 0) {
            await sendQuery(`query Search(
                \$keywords:String!,\$first:Int,\$last:Int,\$after:ID,\$before:ID,
                \$startDate:DateTime,\$endDate:DateTime,\$userIds:[Int!],\$threadType:ThreadType,
                \$sortBy:SearchSorting!,\$skipPages:Int!
            ) {
                search(
                    keywords:\$keywords, first:\$first, after:\$after, before:\$before, last:\$last, 
                    sortBy:\$sortBy, startDate:\$startDate, endDate:\$endDate, userIds:\$userIds, threadsType:\$threadType,
                    skipPages:\$skipPages, withPageCount:true, withLastPageSpecialBehavior:true
                ) {
                    __typename
                    edges {
                        node {
                            thread {
                                __typename
                                ... on TidThread {
                                    id
                                    dbId
                                    authorId
                                    title
                                    deducedDate
                                    minorTag
                                    majorTag
                                    states
                                    kubeCount
                                    pageCount
                                }
                                ... on Thread {
                                    id
                                    dbId
                                    authorId
                                    title
                                    tags
                                    creationDate
                                    lastUpdateDate
                                }
                            }
                            comment {
                                __typename
                                ... on TidComment {
                                    id
                                    dbId
                                    threadId
                                    authorId
                                    states
                                    deducedDate
                                    loadTimestamp
                                    content
                                    contentWarning
                                }
                                ... on Comment {
                                    id
                                    number
                                    threadId
                                    author {
                                        dbId
                                        name
                                        avatarURL
                                    }
                                    creationDate
                                    lastEditionDate
                                    content
                                }
                            }
                            relevance
                        }
                        cursor
                    }
                    pageInfo {
                        hasNextPage
                        hasPreviousPage
                        startCursor
                        endCursor
                        pageCount
                        currPage
                    }
                }
            }`,{keywords:keywords,first:first,last:last,after:after,before:before,sortBy:sortBy,startDate:startDate,endDate:endDate,userIds:userIds,threadType:threadType,skipPages:skipPages}).then((json) => {
                if (json?.data?.search?.edges == null) { basicQueryResultCheck(null,true); return; }

                searchFormResults.innerHTML = '';
                for (const edge of json.data.search.edges) {
                    const item = edge.node;
                    let e = null;
                    switch (item.thread.__typename) {
                        case 'Thread':
                            e = stringToNodes(`<div class="searchItem">
                                <div class="infos">
                                    <p><b>Titre :</b> \${item.thread.title}</p>
                                    <p><b>ID Topic :</b> \${item.thread.dbId}</p>
                                    <p><b>ID Utilisateur :</b> \${item.comment.author.dbId}</p>
                                </div>
                                <div class="content">\${item.comment.content}</div>
                                <div class="footer">
                                    <p>\${item.comment.creationDate} - <a href="$root/forum/\${item.thread.dbId}" target="_blank">Lien</a></p>
                                </div>
                            </div>`)[0];
                            break;
                        case 'TidThread':
                            e = stringToNodes(`<div class="searchItem tid">
                                <div class="infos">
                                    <p><b>Titre :</b> \${item.thread.title}</p>
                                    <p><b>ID Topic :</b> \${item.thread.dbId}</p>
                                    <p><b>ID Utilisateur :</b> \${item.comment.authorId}</p>
                                </div>
                                <div class="content">\${item.comment.content}</div>
                                <div class="footer">
                                    <p>\${item.comment.deducedDate}</p>
                                </div>
                            </div>`)[0];
                            break;
                        default:
                            e = stringToNodes(`<div class="searchItem">
                                <div class="infos">
                                    <p><b>Erreur de chargement</b></p>
                                </div>
                                <div class="content"><p><b>Erreur de chargement</b></p></div>
                                <div class="footer">
                                    <p><b>Erreur de chargement</b></p>
                                </div>
                            </div>`)[0];
                            break;
                    }
                    searchFormResults.insertAdjacentElement('beforeend',e);
                }

                const pageInfo = json.data.search.pageInfo;
                if ((pageInfo.hasNextPage | pageInfo.hasPreviousPage) == true) {
                    for (const node of pagDivs) {
                        node.style.display = '';
                        const left = node.querySelector('.left');
                        const right = node.querySelector('.right');
                        left.dataset.cursor = pageInfo.startCursor;
                        right.dataset.cursor = pageInfo.endCursor;
                        node.querySelector('.nPage').innerHTML = pageInfo.currPage;
                        node.querySelector('.nMaxPages').innerHTML = pageInfo.pageCount;
                    }
                } else {
                    for (const node of pagDivs) node.style.display = 'none';
                }
            });
        }

        function loadDatalist(twinoidUsers = false) {
            sendQuery(`query Userlist(\$twinoidUsers:Boolean!) {
                userlist(first:500,twinoidUsers:\$twinoidUsers) {
                    edges {
                        node {
                            ... on RegisteredUser {
                                id
                                dbId
                                name
                            }
                            ... on TidUser {
                                id
                                dbId
                                name
                            }
                        }
                    }
                }
            }`,{twinoidUsers:twinoidUsers}).then((json) => {
                if (json?.data?.userlist?.edges == null) { basicQueryResultCheck(); return; }

                const eUserlist = document.querySelector('#dl_userlist');
                eUserlist.innerHTML = '<option></option>';
                eUserlist.disabled = false;
                for (const edge of json.data.userlist.edges) {
                    const user = edge.node;
                    eUserlist.insertAdjacentHTML('beforeend',`<option>\${user.name}(\${user.dbId})</option>`);
                }
            });
        }
    }
    function contentToText(s) {
        let res = '';
        let sRpTextSpeaker = '';
        let sPreQuote = '';
        for (const node of s) {
            switch (node.nodeName) {
                case '#text': res += node.textContent; break;
                case 'IMG': res += node.alt; break;
                case 'BR': res += '\\n'; break;
                case 'B': res +=  '**' + contentToText(node.childNodes) + '**'; break;
                case 'I': res +=  '//' + contentToText(node.childNodes) + '//'; break;
                case 'S': res +=  '--' + contentToText(node.childNodes) + '--'; break;
                case 'PRE': res += '[code]' + contentToText(node.childNodes) + '[/code]\\n'; break;
                case 'CODE': res += contentToText(node.childNodes); break;
                case 'A': res += `[link=\${node.href}]` + contentToText(node.childNodes) + '[/link]'; break;
                case 'BLOCKQUOTE':
                    if (sPreQuote != '') res += `[cite=\${sPreQuote}]` + contentToText(node.childNodes) + '[/cite]\\n';
                    else res += '[cite]' + contentToText(node.childNodes) + '[/cite]\\n';
                    sPreQuote = '';
                    break;
                case 'DIV':
                    if (node.classList.contains('rpText')) {
                        if (sRpTextSpeaker != '') res += `[rp=\${sRpTextSpeaker}]` + contentToText(node.childNodes) + '[/rp]\\n';
                        else res += '[rp]' + contentToText(node.childNodes) + '[/rp]\\n';
                        sRpTextSpeaker = '';
                    } else if (node.classList.contains('rpTextSpeaker')) sRpTextSpeaker = node.innerText;
                    else res += contentToText(node.childNodes);
                    break;
                case 'SPAN':
                    if (node.classList.contains('spoil')) res += '[spoil]' + contentToText(node.childNodes) + '[/spoil]';
                    else res += contentToText(node.childNodes);
                    break;
                case 'P':
                    if (node.classList.contains('spoil')) res += '[spoil=block;]' + contentToText(node.childNodes) + '[/spoil]\\n';
                    else if (node.classList.contains('preQuote')) sPreQuote = node.innerText;
                    else res += contentToText(node.childNodes);
                    break;
                default:
                    console.error('unknown: '+node.nodeName);
            }
        }
        return res;
    }
    function getIconDiv(className,srcNone,srcUnlit,srcLit,fAdd,fRem) {
        if (fRem == null) fRem = async () => null;

        const div = stringToNodes(`<div class="iconDiv \${className}">
            <img class="iconDiv_begin \${className}_begin" src="\${srcNone}"/>
            <div class="iconDiv_mid \${className}_mid"><p>···</p></div>
            <img class="iconDiv_end \${className}_end" src="$res/design/like_end.png" />
        </div>`)[0];
        const divBegin = div.querySelector('.iconDiv_begin');
        const divMid = div.querySelector('.iconDiv_mid');
        const divEnd = div.querySelector('.iconDiv_end')
        const eCount = div.querySelector('.iconDiv_mid p');
        function hideAmount(b = true) { divMid.style.display = divEnd.style.display = b ? 'none' : ''; }
        hideAmount();

        let bKubeProcess = false;
        divBegin.addEventListener('click', () => {
            if (bKubeProcess) return;
            bKubeProcess = true;

            if (divBegin.src == srcUnlit || divBegin.src == srcNone) {
                fAdd().then((o) => {
                    bKubeProcess = false;
                    if (o?.amount == null) return;
                    div.set(o.amount, o?.lit??true);
                });
            } else {
                fRem().then((o) => {
                    bKubeProcess = false;
                    if (o?.amount == null) return;
                    div.set(o.amount, o?.lit??false);
                });
            }
        });

        div.set = (n,lit) => {
            if (n == 0) { hideAmount(); divBegin.src = srcNone; }
            else { hideAmount(false); divBegin.src = lit ? srcLit : srcUnlit; }
            eCount.innerHTML = n;
        };

        return div;
    }
    function getKubeDiv(fAdd, fRem) {
        return getIconDiv("kubeDiv","$res/design/like_icon_none.png","$res/design/like_icon.png","$res/design/like_icon_on.png",fAdd,fRem);
    }
    function getOctohitDiv(fAdd, fRem) {
        return getIconDiv("octohitDiv","$res/design/hit_icon_none.png","$res/design/hit_icon.png","$res/design/hit_icon_on.png",fAdd,fRem);
    }
    const forumFooter =  document.querySelector('#forumL .forum_footer');
    setupPagInput(forumFooter,20,
        () => loadThreads(20),
        () => loadThreads(null,20),
        (n,cursor,skipPages) => loadThreads(null,n,null,cursor,skipPages),
        (n,cursor,skipPages) => loadThreads(n,null,cursor,null,skipPages)
    );
    
    let savedCategories = null;
    function setupReplyForm(replyFormDiv, onSubmit, contentSaveName=null) {
        const replyForm = replyFormDiv.querySelector('.replyForm');
        const replyFormTA = replyFormDiv.querySelector('textarea');
        const eFileInput = replyForm.querySelector('.buttonBar input[type="file"]');
        let acReplyForm = null;
        let toReplyForm = null;
        
        const files = [];
        const objectURLs = new Map();
        let filesToUpload = {};

        function escapeCharacters(s) {
            return s.replaceAll(/[\\*\\/\\-\\[\\]]/g,(s) => '\\\\'+s);
        }
        
        replyFormTA.addEventListener('input',() => {
            if (toReplyForm != null) clearTimeout(toReplyForm);
            toReplyForm = setTimeout(() => {
                if (acReplyForm != null) acReplyForm.abort();
                acReplyForm = new AbortController();
                const sToParse = replyFormTA.value;
                sendQuery(`query ParseText(\$msg:String!) {
                    parseText(text:\$msg)
                }`,{msg:sToParse},null,'ParseText',{signal:acReplyForm.signal}).then((json) => {
                    acReplyForm = null;
                    if (json?.data?.parseText == null) { basicQueryResultCheck(null,true); return; }

                    const nodePreview = replyFormDiv.querySelector('.preview');
                    const nodes = stringToNodes(json.data.parseText);
                    const o = processComment(nodePreview,nodes,{files:files,objectURLs:objectURLs,forPreview:true});
                    filesToUpload = o.filesToUpload;

                    if (contentSaveName != null) sessionSet(contentSaveName,sToParse);
                }).catch((e) => {if (e.name != 'AbortError') throw e; } );
            },100);
        });
        replyFormTA.addEventListener('paste',(e) => {
            if (e.clipboardData.files.length > 0) {
                const a = [...e.clipboardData.files];
                for (const file of a) {
                    inputFile(file);
                }
            }

            if (new RegExp('^(https?|ftp)://[^\\.]+\.[^\\.]+$').test(e.clipboardData.getData('text/plain'))) {
                e.preventDefault();
                const v = e.clipboardData.getData('text/plain');
                quickInputInsert(`[link=\${v}]\${v}[/link]`);
            } else if (replyFormDiv.querySelector('.opt_specChar').checked) {
                e.preventDefault();
                const v = escapeCharacters(e.clipboardData.getData('text/plain'));
                const start = replyFormTA.selectionStart;
                const end = replyFormTA.selectionEnd;
                replyFormTA.value = replyFormTA.value.slice(0,start) + v + replyFormTA.value.slice(end);
                replyFormTA.selectionStart = replyFormTA.selectionEnd = start+v.length;
                replyFormTA.dispatchEvent(new Event('input'));
            }
        });
        replyFormTA.addEventListener('drop',(e) => {
            e.preventDefault();
            const a = [];
            for (const v of [...e.dataTransfer.items]) if (v.kind === 'file') a.push(v.getAsFile());
            if (a.length > 0) inputFile(a);
        });
        replyFormTA.addEventListener('dragover',(e) => e.preventDefault());
        if (contentSaveName != null) {
            replyFormTA.value = sessionGet(contentSaveName)??'';
            replyFormTA.dispatchEvent(new Event('input'));
        }
        
        replyForm.addEventListener('submit',async (e) => {
            e.preventDefault();
            const res = await onSubmit(e,filesToUpload);
            if (contentSaveName != null && res === true) sessionRem(contentSaveName);
        });

        replyFormDiv.querySelector('.previewToggler').addEventListener('click',() => {
            var v = replyFormDiv.querySelector('.preview').style.display;
            replyFormDiv.querySelector('.preview').style.display = v == 'none' ? '' : 'none';
        });

        function quickInputInsert(s1,s2) {
            const msg = replyFormTA.value;
            const start = replyFormTA.selectionStart;
            const end = replyFormTA.selectionEnd;

            if (s2 == null) {
                replyFormTA.value = msg.substring(0,start) + s1 + msg.substring(start);
                replyFormTA.selectionStart = replyFormTA.selectionEnd = start+s1.length;
            } else {
                replyFormTA.value = msg.substring(0,start) + s1 + msg.substring(start,end) + s2 + msg.substring(end);
                const diff = replyFormTA.selectionEnd-replyFormTA.selectionStart;
                replyFormTA.selectionStart = start+s1.length;
                replyFormTA.selectionEnd = end+s1.length+diff;
            }
            replyFormTA.focus();
            replyFormTA.dispatchEvent(new InputEvent('input'));
        }
        replyForm.querySelector('.buttonBar .bold').addEventListener('click',() => quickInputInsert('**','**'));
        replyForm.querySelector('.buttonBar .italic').addEventListener('click',() => quickInputInsert('//','//'));
        replyForm.querySelector('.buttonBar .strike').addEventListener('click',() => quickInputInsert('--','--'));
        replyForm.querySelector('.buttonBar .link').addEventListener('click',() => {
            const link = prompt("Le lien que vous voulez insérer :");
            if (link == null) return;
            const txt = replyFormTA.selectionStart === replyFormTA.selectionEnd ? prompt("Entrer le texte de votre lien :")??''  : '';
            quickInputInsert(`[link=\${link}]\${txt}`,'[/link]');
        });
        replyForm.querySelector('.buttonBar .cite').addEventListener('click',() => quickInputInsert('[cite]','[/cite]'));
        replyForm.querySelector('.buttonBar .spoil').addEventListener('click',() => quickInputInsert('[spoil]','[/spoil]'));
        replyForm.querySelector('.buttonBar .rp').addEventListener('click',() => quickInputInsert('[rp]','[/rp]'));
        replyForm.querySelector('.buttonBar .code').addEventListener('click',() => quickInputInsert('[code]','[/code]'));
        replyForm.querySelector('.buttonBar .file').addEventListener('click',() => eFileInput.click());
        eFileInput.addEventListener('change', () => { inputFile(eFileInput.files); eFileInput.value = null; });

        function inputFile(filesToAdd) {
            if (!isIterable(filesToAdd)) filesToAdd = [filesToAdd];
            
            for (const file of filesToAdd) {
                if (file.size > 25000000) { alert('Le fichier ne doit pas faire plus de 25MB.'); return; }
                files.push(file);
                quickInputInsert(`[file=\${escapeCharacters(file.name)}/]`);
            }
        }

        // Emojis
        if (savedCategories == null) sendQuery(`query {
            viewer {
                emojis(first:2000,withPageCount:true) {
                    edges {
                        node {
                            dbId
                            srcPath
                            aliases
                            consommable
                            amount
                        }
                    }
                    pageInfo {
                        hasNextPage
                        hasPreviousPage
                        startCursor
                        endCursor
                        pageCount
                        currPage
                    }
                }
            }
            }`).then((json) => {
                if (json?.data?.viewer?.emojis?.edges == null) { basicQueryResultCheck(); return; }

                const emojis = json.data.viewer.emojis;
                const categories = {};
                for (const edge of emojis.edges) {
                    const node = edge.node;
                    const m = /^tid\/([\w\s]*)\/([^\/]+)$/.exec(node.dbId);
                    if (m == null) continue;
                    if (!(m[1] in categories)) categories[m[1]] = new Map();
                    const category = categories[m[1]];

                    category.set(node.dbId,{src:node.srcPath, aliases:node.aliases, consommable:node.consommable, amount:node.amount});
                }
                savedCategories = categories;
                loadEmojis(categories);
            });
        else loadEmojis(savedCategories);

        function loadEmojis(categories) {
            const emojisButtons = replyFormDiv.querySelector('.emojisButtons');
            const emojisCont = replyFormDiv.querySelector('.emojis');
            emojisButtons.innerHTML = '';
            for (const cat in categories) {
                const butNode = stringToNodes(`<button class="button1" type="button">\${cat}</button>`)[0];
                butNode.addEventListener('click',() => {
                    emojisCont.innerHTML = '';
                    for (const [id,emoji] of categories[cat]) {
                        const emojiNode = stringToNodes(`<button type="button"><img src="\${emoji.src}" alt="\${emoji.aliases[0]}"/></button>`)[0];
                        emojisCont.insertAdjacentElement('beforeend',emojiNode);

                        function testAlias(s) { return s.charAt(0) == ':' && s.charAt(s.length-1) == ':'; }
                        emojiNode.addEventListener('click',() => {
                            const msg = replyFormTA.value;
                            const start = replyFormTA.selectionStart;
                            let s1 = '';
                            for (const s of emoji.aliases) if (testAlias(s)) { s1 = s; break; }
                            if (s1 == '') { replyFormTA.focus(); return; }

                            replyFormTA.value = msg.substring(0,start) + s1 + msg.substring(start);
                            replyFormTA.focus();
                            replyFormTA.selectionStart = replyFormTA.selectionEnd = start+s1.length;
                            replyForm.dispatchEvent(new InputEvent('input'));
                        });
                    }
                });
                emojisButtons.insertAdjacentElement('beforeend',butNode);
            }

            const gadgetsCat = stringToNodes(`<button class="button1" type="button">Gadgets</button>`)[0];
            gadgetsCat.addEventListener('click',() => {
                emojisCont.innerHTML = '';
                const cardNode = stringToNodes(`<button type="button"><img src="$res/design/balises/card.png" alt="[card/]"/></button>`)[0];
                cardNode.addEventListener('click',() => quickInputInsert('[card/]'));
                emojisCont.insertAdjacentElement('beforeend',cardNode);
                const letterNode = stringToNodes(`<button type="button"><img src="$res/design/balises/letter.png" alt="[letter/]"/></button>`)[0];
                letterNode.addEventListener('click',() => quickInputInsert('[letter/]'));
                emojisCont.insertAdjacentElement('beforeend',letterNode);
                const consonNode = stringToNodes(`<button type="button"><img src="$res/design/balises/conson.png" alt="[letter=consonne/]"/></button>`)[0];
                consonNode.addEventListener('click',() => quickInputInsert('[letter=consonne/]'));
                emojisCont.insertAdjacentElement('beforeend',consonNode);
                const vowelNode = stringToNodes(`<button type="button"><img src="$res/design/balises/vowel.png" alt="[letter=voyelle/]"/></button>`)[0];
                vowelNode.addEventListener('click',() => quickInputInsert('[letter=voyelle/]'));
                emojisCont.insertAdjacentElement('beforeend',vowelNode);
            });
            emojisButtons.insertAdjacentElement('beforeend',gadgetsCat);
        }
    }
    let newReplyFormC = 0;
    function getNewReplyForm() {
        newReplyFormC++;
        return stringToNodes(`<div class="replyFormDiv">
                <a class="previewToggler" href="#" onclick="return false;">Masquer / afficher l'aperçu de votre message</a>
                <div class="preview"></div>
            
                <form class="replyForm">
                    <div class="buttonBar">
                        <div>
                            <button class="button1 bold" type="button">G</button><!--
                            --><button class="button1 italic" type="button">I</button><!--
                            --><button class="button1 strike" type="button">Barré</button><!--
                            --><button class="button1 link" type="button">Lien</button><!--
                            --><button class="button1 cite" type="button">Citer</button><!--
                            --><button class="button1 spoil" type="button">Spoil</button><!--
                            --><button class="button1 rp" type="button">Roleplay</button><!--
                            --><button class="button1 code" type="button">Code</button>
                        </div>
                        <div>
                            <input type="file" style="display:none;" multiple="true"/>
                            <button class="button1 file" type="button">Insérer un fichier</button>
                        </div>
                    </div>
                    <textarea name="msg"></textarea>
                    <div class="optDiv">
                        <label for="opt_specChar_\${newReplyFormC}">Coller échappe les caractères spéciaux : </label><input id="opt_specChar_\${newReplyFormC}" class="opt_specChar" type="checkbox" />
                    </div>
                    <div class="emojisDiv">
                        <div class="emojisButtons"></div>
                        <div class="emojis"></div>
                    </div>
                    <input class="button2" type="submit" value="Envoyer"/>
                </form>
            </div>`)[0];
    }
    function processComment(container, commNodes, withData) {
        container.innerHTML = '';
        const files = withData?.files ?? [];
        const objectURLs = withData?.objectURLs ?? new Map();
        const forPreview = withData?.forPreview === true;
        const o = {filesToUpload:{}};
        for (const node of Array.from(commNodes)) {
            // processThis
            const nodesToProcess = [];
            if (node.classList.contains('processThis')) nodesToProcess.push(node);
            for (const n of node.querySelectorAll('.processThis')) nodesToProcess.push(n);

            for (const node of Array.from(nodesToProcess)) {
                const m = /^(\w+):(.*)$/.exec(node.innerHTML);
                if (m == null) continue;

                const fName = m[1];
                let local = false;
                switch (m[1]) {
                    case 'insertFile':
                        const keyName = m[2];
                        const viewNode = stringToNodes(`<button class="button1">View file</button>`)[0];
                        node.replaceWith(viewNode);
                        let bLoading = false;
                        viewNode.addEventListener('click',() => {
                            if (bLoading) return;
                            bLoading = true;
                            viewNode.innerHTML = 'Loading...';
                            
                            fetch(`$res/file/\${keyName}`).then((res) => {
                                bLoading = false;
                                if (res.status == 404) { viewNode.innerHTML = 'File not found.'; return 'ignore'; }
                                try { return res.blob(); } catch (e) { return null; }
                            }).then((blob) => {
                                if (blob == 'ignore') return;
                                if (blob?.constructor?.name != 'Blob') { viewNode.innerHTML = '<span>Incorrect file.</span>'; return; }

                                const imgRegex = new RegExp('^image\\/*');
                                const vidRegex = new RegExp('^video\\/*');
                                if (imgRegex.test(blob.type)) {
                                    viewNode.replaceWith(stringToNodes(`<img class="inserted file" src="$res/file/\${keyName}" alt="[file=get;\${keyName}/]"/>`)[0]);
                                } else if (vidRegex.test(blob.type)) {
                                    viewNode.replaceWith(stringToNodes(`<video class="inserted file" controls="true" preload="auto" playsinline muted> <source src="$res/file/\${keyName}" alt="[file=get;\${keyName}/]"/> </video>`)[0]);
                                } else {
                                    const but = stringToNodes(`<button class="button1 inserted file">Télécharger \${keyName}<a href="$res/file/\${keyName}" target="_blank" style="display:none;"></a></button>`)[0];
                                    but.addEventListener('click',() => but.querySelector('a').click());
                                    viewNode.replaceWith(but);
                                }
                            });
                        });
                        if (!forPreview) viewNode.click();
                        break;
                    case 'insertFileLocal':
                        function replaceByFile(node, file, url) {
                            const viewNode = stringToNodes(`<button class="button1">View file</button>`)[0];
                            node.replaceWith(viewNode);
                            viewNode.addEventListener('click',() => {
                                const imgRegex = new RegExp('^image\\/*');
                                const vidRegex = new RegExp('^video\\/*');
                                if (imgRegex.test(file.type)) {
                                    viewNode.replaceWith(stringToNodes(`<img class="inserted file" src=\${url} />`)[0]);
                                } else if (vidRegex.test(file.type)) {
                                    viewNode.replaceWith(stringToNodes(`<video class="inserted file" controls="true" preload="auto" playsinline muted> <source src="\${url}" /> </video>`)[0]);
                                } else {
                                    const but = stringToNodes(`<button class="button1">Télécharger \${file.name}<a href="\${url}" target="_blank" style="display:none;"></a></button>`)[0];
                                    but.addEventListener('click',() => but.querySelector('a').click());
                                    viewNode.replaceWith(but);
                                }
                            });
                        }

                        let file = null;
                        for (const f of files) if (f.name == m[2]) file = f;

                        if (file == null) {
                            const but = stringToNodes(`<button class="button1 warning" type="button">Reuploader \${m[2]}</button>`)[0];
                            const hiddenFileInput = stringToNodes(`<input type="file" style="display:none;" />`)[0]
                            container.insertAdjacentElement('afterend',hiddenFileInput);
                            node.replaceWith(but);

                            but.addEventListener('click', () => hiddenFileInput.click());
                            hiddenFileInput.addEventListener('change',() => {
                                const file = hiddenFileInput.files[0];
                                if (file.name != m[2]) { alert('Le nom du fichier est différent.'); return; }

                                files.push(file);
                                const url = URL.createObjectURL(file);
                                objectURLs.set(file.name,url);
                                replaceByFile(but,file,url);
                                o.filesToUpload[file.name] = file;
                            });
                            break;
                        }

                        let url = objectURLs.get(file.name);
                        if (url == null) {
                            url = URL.createObjectURL(file);
                            objectURLs.set(file.name,url);
                        }
                        
                        replaceByFile(node,file,url);
                        o.filesToUpload[file.name] = file;
                        break;
                }
            }

            // gadgets
            const letterGadgets = [];
            if (node.classList.contains('gadget') && node.classlist.contains('letter')) letterGadgets.push(node);
            for (const n of node.querySelectorAll('.gadget.letter')) letterGadgets.push(n);
            for (const node of letterGadgets) {
                const regex = new RegExp('^;*(?:A\-Z|consonne|voyelle|inspect)(?:(?:;inspect|;)+)?$')
                if (node.dataset.generator == '' || regex.test(node.dataset.generator)) node.classList.add('approved');
            }

            if (node.classList.contains('gadget') && node.classlist.contains('card')) letterGadgets.push(node);
            for (const n of node.querySelectorAll('.gadget.card')) letterGadgets.push(n);
            for (const node of letterGadgets) {
                const regex = new RegExp('^;*(?:inspect)(?:(?:;inspect|;)+)?$')
                if (node.dataset.generator == '' || regex.test(node.dataset.generator)) node.classList.add('approved');
            }

            container.insertAdjacentElement('beforeend',node);
        }
        return o;
    }

    document.querySelector('.newThreadLoader').addEventListener('click',loadNewThreadForm);
    document.querySelector('.searchLoader').addEventListener('click',loadSearchForm);
    document.querySelector('.refreshThreads').addEventListener('click',() => {
        const pageNumber = parseInt(document.querySelector('#forumL .nPage').innerText);
        if (isNaN(pageNumber)) return;
        loadThreads(20,null,null,null,pageNumber-1);
    });

    loadThreads(20);

    const m = new RegExp("^$root/forum/(\\\d+)").exec(location.href);
    if (m != null) loadThread(`forum_\${m[1]}`,10,null,null,null,0,false,true);
    LinkInterceptor.addMidProcess('forumMP', (url,displayedURL,stateAction) => {
        if (document.querySelector('#mainDiv_forum') == null) return false;
        const m = new RegExp("^$root/forum(?:/(\\\d+)?)?$").exec(displayedURL);
        if (m == null) return false;
        else if (m[1] != null) loadThread(`forum_\${m[1]}`,10,null,null,null,0,false,true);
        else {
            forumR.innerHTML = '';
            if (mobileMode) { forumR.style.display = 'none'; forumL.style.display = ''; }
            highlightThread(null);
        }

        switch (stateAction) {
            case StateAction.PushState: history.pushState({pageUrl:url}, "", displayedURL); break;
            case StateAction.ReplaceState: history.replaceState({pageUrl:url}, "", displayedURL); break;
            default: break;
        }
        return true;
    },5);

    const mql = window.matchMedia("(max-width: 800px)");
    const fmql = (mql) => {
        if (mql.matches) {
            if (!mobileMode) {
                if (forumR.innerHTML.trim() != '') { forumL.style.display = 'none'; forumR.style.display = ''; }
                else { forumR.style.display = 'none'; forumL.style.display = ''; }
                for (const e of forumR.querySelectorAll('.mobile.back')) e.style.display = '';
            }
            mobileMode = true;
        } else {
            forumL.style.display = forumR.style.display = '';
            for (const e of forumR.querySelectorAll('.mobile.back')) e.style.display = 'none';
            mobileMode = false;
        }
    };
    mql.addEventListener('change',fmql);
    fmql(mql);


    JAVASCRIPT,
    'css' => <<<CSS
    #mainDiv_forum {
        background: var(--bg-gradient-1);
        min-height: inherit;
        overflow: auto;
    }
    #mainDiv_forum .button1 {
        background-color: var(--color-black-2);
        border: 0;
        border-top: 1px solid #6C7188;
        outline: 1px solid var(--color-black-1);
        padding: 0.2rem 0.4rem 0.2rem 0.4rem;
        color: white;
        font-size: 0.7rem;
        font-weight: bold;
    }
    #mainDiv_forum .button1:hover {
        background-color: #3b4151;
        border-color: var(--color-black-1);
    }
    #mainDiv_forum .button1 img {
        vertical-align: -0.4em;
        margin-right: 0.2rem;
    }
    #mainDiv_forum .button2 {
        border: 0;
        padding: 0.2rem 1.2rem;
        font-weight: bold;
        font-size: 1rem;
        border-top: 1px solid #6C7188;
        border-bottom: 2px solid #3b4151;
        border-radius: 2px;
        box-shadow: 0px 1px 3px black;
        background-color: var(--color-black-2);
        color: white;
        margin: 0.5rem 0px 0px 0.2rem;
    }
    #mainDiv_forum .button2:hover {
        background-color: #3b4151;
        border-top-color: var(--color-black-1);
        border-bottom: 0px;
        padding-bottom: 0.3rem;
        box-shadow: inset 0px 0px 2px black;
    }
    #mainDiv_forum .button3 {
        padding: 0.3em 0.5em;
        background-color: #FF6600;
        box-shadow: inset 0px 15px 0px #ff7900, inset 0px -1px 0px #a63f00, 0px 2px 2px rgb(0 0 0 / 40%);
        border: 1px solid #FF6600;
        border-radius: 2px;
        text-shadow: 0px 1px 2px black;
        color: white;
        font-size: 0.8rem;
    }
    #mainDiv_forum .button3:hover {
        border-color: white;
    }
    #mainDiv_forum .inputText1 {
        font-size: 0.75rem;
        border: 0;
        box-shadow: inset 0px 2px 2px #c7c5c0;
        padding: 0.1rem 1rem 0.1rem 0px;
    }
    #mainDiv_forum .inputText1:invalid {
        color: red;
    }
    #mainDiv_forum .replyFormDiv {
        margin: 2.2rem 0px;
        padding-top: 2rem;
        border-top: 1px dashed black;
        transition: all 0.5s;
        min-height: 32rem;
        overflow: hidden;
    }
    #mainDiv_forum .replyFormDiv.hide {
        height: 0px;
        border: 0px;
        margin: 0;
        min-height: 0;
    }
    #mainDiv_forum .replyFormDiv.hide * {
        display: none !important;
    }
    #mainDiv_forum .replyFormDiv .previewToggler {
        border: 1px dashed #00000050;
        padding: 0.1em 0.2em;
        font-size: 0.7rem;
        text-decoration: none;
        margin: 0px 0px 0.4em 0px;
        display: inline-block;
        cursor: pointer;
    }
    #mainDiv_forum .replyFormDiv .preview {
        min-height: 9rem;
        background-color: #F4F3F2;
        border-top: 1px solid rgba(0,0,0, 0.4);
        box-shadow: 0px 2px 2px rgb(0 0 0 / 15%);
        font-size: 0.83rem;
        line-height: 1.15;
        padding: 0.6rem 1rem 0.6rem 5.9rem;
        margin: 0px 0px 1rem 0px;
        white-space: pre-wrap;
    }
    #mainDiv_forum .replyFormDiv .preview blockquote,
    #mainDiv_forum .comment .body blockquote,
    #mainDiv_forum #searchFormResults .content blockquote,
    #mainDiv_forum #searchFormResults .searchItem.tid .content cite {
        padding: 0.3rem 0px 0.3rem 0.3rem;
        border-left: 1px dashed rgba(0,0,0, 0.6);
        border-bottom: 1px dashed rgba(0,0,0, 0.6);
        font-style: italic;
        opacity: 0.7;
        margin: 0.2rem 0.3rem 0.5rem 0.3rem;
    }
    #mainDiv_forum .replyFormDiv .preview .preQuote,
    #mainDiv_forum .comment .body .preQuote,
    #mainDiv_forum #searchFormResults .content .preQuote,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_preCite *:not(.tid_user) {
        font-size: 80%;
        font-weight: bold;
        margin: 0.2rem 0px 0px 0px;
    }
    #mainDiv_forum .replyFormDiv .preview .spoil,
    #mainDiv_forum .comment .body .spoil,
    #mainDiv_forum #searchFormResults .content .spoil,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_spoil {
        cursor: help;
        background-image: url({$res}/design/spoiler.png);
    }
    #mainDiv_forum .replyFormDiv .preview .spoil .spoilTxt,
    #mainDiv_forum .comment .body .spoil .spoilTxt,
    #mainDiv_forum #searchFormResults .content .spoil .spoilTxt,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_wspoil {
        opacity:0;
    }
    #mainDiv_forum .replyFormDiv .preview .spoil:hover,
    #mainDiv_forum .comment .body .spoil:hover,
    #mainDiv_forum #searchFormResults .content .spoil:hover,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_spoil:hover {
        background-image: url({$res}/design/spoiler_hover.png);
    }
    #mainDiv_forum .replyFormDiv .preview .spoil:hover .spoilTxt,
    #mainDiv_forum .comment .body .spoil:hover .spoilTxt,
    #mainDiv_forum #searchFormResults .content .spoil:hover .spoilTxt,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_wspoil:hover {
        opacity:unset;
    }
    #mainDiv_forum .replyFormDiv .preview .rpTextSpeaker > p::before,
    #mainDiv_forum .comment .body .rpTextSpeaker > p::before,
    #mainDiv_forum #searchFormResults .content .rpTextSpeaker > p::before,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_preRoleplay::before {
        content: url({$res}/icons/rp.png);
        margin: 0px 0.2em 0px 0px;
    }
    #mainDiv_forum .replyFormDiv .preview .rpTextSpeaker,
    #mainDiv_forum .comment .body .rpTextSpeaker,
    #mainDiv_forum #searchFormResults .content .rpTextSpeaker,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_preRoleplay {
        font-weight: bold;
        font-size: 90%;
        font-style: italic;
        margin: 0.6em 0px 0px 1%;
    }
    #mainDiv_forum .replyFormDiv .preview .rpText::before,
    #mainDiv_forum .comment .body .rpText::before,
    #mainDiv_forum #searchFormResults .content .rpText::before,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_roleplay::before {
        display: block;
        position:absolute;
        content: url({$res}/design/arrowUp.png);
        transform: translate(0.5rem, -83%);
    }
    #mainDiv_forum .replyFormDiv .preview .rpText,
    #mainDiv_forum .comment .body .rpText,
    #mainDiv_forum #searchFormResults .content .rpText,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_roleplay {
        background: #dddbd8;
        border: 1px solid #efefef;
        box-shadow: 0px 0px 2px black;
        border-radius: 6px;
        padding: 3px;
        font-size: 0.9rem;
        margin: 0.5rem 20% 0.5rem 0.75rem;
    }
    #mainDiv_forum .replyFormDiv .preview pre,
    #mainDiv_forum .comment .body pre,
    #mainDiv_forum #searchFormResults .content pre {
        padding: 5px;
        box-shadow: inset 0px 1px 2px rgba(0,0,0, 0.35);
        margin: 0.5rem 0px;
        border: 1px solid rgba(255,255,255, 0.5);
        overflow: auto;
        font-size: 0.7rem;
        max-height: 71em;
    }
    #mainDiv_forum .replyFormDiv .preview .button1.warning,
    #mainDiv_forum .comment .body .button1.warning,
    #mainDiv_forum #searchFormResults .content .button1.warning {
        box-shadow: 0px 0px 0.5em 0.2em red;
    }
    #mainDiv_forum .replyFormDiv .preview .gadget,
    #mainDiv_forum .comment .body .gadget,
    #mainDiv_forum #searchFormResults .content .gadget {
        background-color: #DF0000;
        color: white;
        padding: 0.15em 0.4em 0.15em 0.4em;
        border-radius: 0.2rem;
        font-weight: bold;
        display: inline-flex;
        font-size: 80%;
        vertical-align: middle;
        margin: 0.1em;
        align-items: center;
    }
    #mainDiv_forum .replyFormDiv .preview .gadget.approved,
    #mainDiv_forum .comment .body .gadget.approved,
    #mainDiv_forum #searchFormResults .content .gadget.approved {
        background-color: #3B4151;
    }
    #mainDiv_forum .replyFormDiv .preview .gadget .value,
    #mainDiv_forum .comment .body .gadget .value,
    #mainDiv_forum #searchFormResults .content .gadget .value {
        margin: 0.1rem 0px 0px 0.2rem;
        text-indent: 0px;
        word-break: break-all;
    }
    #mainDiv_forum .inserted {
        max-width: 85%;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content cite,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_spoil,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_preRoleplay,
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_roleplay {
        display: block;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_questionModule {
        margin: 20px 0px;
        padding: 10px;
        min-width: 180px;
        border: 1px solid rgba(255,255,255, 0.1);
        border-top: 0px;
        border-radius: 3px;
        box-shadow: 1px 1px 3px black;
        box-shadow: 1px 1px 3px rgba(0,0,0, 0.5);
        background-color: #dddbd8;
        background-image: url({$res}/design/gripWhite.png);
        background-position: center top;
        background-repeat: repeat-x;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_questionModule .tid_questionLine {
        opacity: 0.85;
        padding: 15px;
        border-bottom: 1px dashed rgba(0,0,0,0.15);
        color: black;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_questionModule .tid_questionResults {
        display: block;
        float: right;
        min-width: 170px;
        max-width: 190px;
        margin-left: 15px;
        margin-bottom: 10px;
        white-space: nowrap;
        text-align: right;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_questionModule .tid_questionResults .tid_value {
        vertical-align: middle;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_questionModule .tid_button.tid_mini.tid_bVote {
        display:none;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_questionModule .tid_barBG {
        height: 15px;
        display: inline-block;
        vertical-align: middle;
        width: 150px;
        height: 15px;
        overflow: hidden;
        border-radius: 3px;
        box-shadow: inset 0px 0px 8px rgba(0,0,0,0.3);
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_questionModule .tid_barInner {
        background-color: #fe7d00;
        height: 100%;
        box-shadow: inset 0px -8px 0px rgba(0,0,0,0.15), inset 1px 0px 2px #5B1E00;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_questionModule .tid_footer {
        margin-left: -10px;
        margin-right: -10px;
        margin-top: 0px;
        margin-bottom: 0px;
        padding: 15px 15px;
        opacity: 0.6;
        font-weight: bold;
        border-top: 1px solid rgba(255,255,255,0.2);
        box-shadow: 0px -4px 4px rgba(0,0,0,0.15);
        display: none;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_user {
        background-image: url({$res}/icons/notContact.png);
        background-repeat: no-repeat;
        background-position: top right;
        border-top: 1px solid #fe7d00;
        background-color: #bd3d00;
        padding-left: 4px;
        color: white;
        box-shadow: 0px 0px 1px black;
        white-space: nowrap;
        border-radius: 4px;
        padding-right: 13px !important;
        cursor: default;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_announce {
        display: block;
        margin: 1em;
        padding: 0.7em;
        padding-left: 2em;
        border: 1px solid #6B7087;
        border-radius: 4px;
        text-shadow: 0px 1px 0px #3b4151;
        color: white;
        background-image: url({$res}/design/announceBg.png);
        background-position: bottom left;
        background-repeat: no-repeat;
        background-color: #3b4151;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_mod::before {
        content: "Message d'un modérateur";
        position: absolute;
        top: 0.5em;
        color: #F4DF8B;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_mod {
        display: block;
        margin: 1em;
        padding: 0.7em;
        padding-left: 0.75em;
        padding-top: 2em;
        background-color: #bd3d00;
        border-radius: 3px;
        color: white;
        position: relative;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_strike {
        text-decoration: line-through;
        opacity: 0.8;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content em {
        opacity: 0.7;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content strong {
        color: #3b4151;
    }
    #mainDiv_forum #searchFormResults .searchItem.tid .content .tid_announce strong {
        color: inherit;
    }
    #mainDiv_forum .replyFormDiv .replyForm {
        box-shadow: 0px 1px 2px black;
        color: white;
        background-color: var(--color-black-2);
        margin: 0.8rem 0px 0px 0px;
        padding: 0.7rem;
        border-radius: 0.3rem;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
    }
    #mainDiv_forum .replyFormDiv .replyForm .formTitle {
        padding: 0.4em 0.3em 0.3em 0.3em;
        margin: 0em 0em 0.2em 0em;
        border: 2px dashed red;
        font-weight: bold;
    }
    #mainDiv_forum .replyFormDiv .replyForm div.title {
        display: flex;
        width: 100%;
        align-items: center;
        padding: 0px 0px 0.4rem 1rem;
    }
    #mainDiv_forum .replyFormDiv .replyForm div.title label {
        min-width: 8%;
        font-size: 0.9rem;
        padding: 0.1rem 0.3rem 0px 0px;
        font-weight: bold;
    }
    #mainDiv_forum .replyFormDiv .replyForm .buttonBar {
        margin: 0rem 0rem 0.2rem 0.1rem;
        display: flex;
        justify-content: space-between;
        width: 100%;
        padding: 0px 0.1rem 0px 0px;
    }
    #mainDiv_forum .replyFormDiv .replyForm .buttonBar button {
        min-width: 1.8rem;
        padding: 0.2rem 0.6rem;
        font-size: 0.75rem;
    }
    #mainDiv_forum .replyFormDiv .replyForm textarea {
        width: 100%;
        min-height: 13rem;
        font-size: 0.8rem;
        padding: 0.3rem;
        font-family: monospace;
        resize: vertical;
    }
    #mainDiv_forum .replyFormDiv .replyForm .optDiv {
        margin: 0.3em;
        font-size: 0.7em;
    }
    #mainDiv_forum .replyFormDiv .replyForm .optDiv input {
        vertical-align: middle;
    }
    #mainDiv_forum .replyFormDiv .replyForm .emojis {
        background-color: var(--color-grey-lighter);
        max-height: 14rem;
        overflow: auto;
        margin: 0.3rem 0px 0px 0px;
        padding: 0.5rem;
    }
    #mainDiv_forum .replyFormDiv .replyForm .emojis button {
        border: 0;
    }
    #mainDiv_forum .replyFormDiv .replyForm .emojis button img {
        max-width: 4rem;
        max-height: 4rem;
        margin: 0.1em;
    }
    #forum_banner {
        display: flex;
        justify-content: center;
    }
    #forum_banner img {
        max-width:100%;
    }
    #forumL {
        flex: 1 1 37%;
        max-width: 37%;
    }
    #forumR {
        flex: 1 0 63%;
        max-width: 63%;
    }
    #forum_content {
        display: flex;
        gap: 2rem;
        margin: 1rem auto 4rem auto;
        width: 980px;
        max-width: 99%;
        justify-content: center;
    }
    #forum_threads {
        margin: 0px 0px 1rem 0px;
        width: 100%;
        word-break: break-word;
    }
    #forum_threads a {
        text-decoration: none;
        color: black;
        display: inline-block;
        width: 100%;
        min-height: 1.9rem;
        height: 100%;
    }
    #forum_threads thead {
        font-size: 0.6rem;
        font-weight: bold;
    }
    #forum_threads thead td {
        font-weight: bold;
        padding: 0px 0px 0.1em 0px;
    }
    #forum_threads tbody td {
        height: 100%;
    }
    #forum_threads tr.thread {
        font-size: 0.8rem;
        height: 1.9rem;
    }
    #forum_threads tr.thread:not(.new) p {
        opacity: 0.55;
    }
    #forum_threads .statusIcons .selectArrow {
        position: absolute;
        transform: translate(-150%,0px);
        display: none;
    }
    #forum_threads .statusIcons .new {
        position: absolute;
        transform: translate(-50%,0px);
    }
    #forum_threads tbody tr[data-selected="true"] .statusIcons, #forum_threads tbody tr[data-selected="true"] .quickDetails{
        background-color: #B6B8BD;
    }
    #forum_threads tbody tr[data-selected="true"] .title {
        background-color: #9FA2AA;
        box-shadow: inset 0px 0px 2px black;
    }
    #forum_threads tbody tr[data-selected="true"] .selectArrow {
        display: initial;
    }
    #forum_threads .statusIcons, #forum_threads .quickDetails {
        background-color: #C4C5C8;
    }
    #forum_threads .statusIcons {
        width: 8%;
    }
    #forum_threads .statusIcons > a {
        display: flex;
        align-items: center;
        justify-content: center;
    }
    #forum_threads .statusIcons > a > div {
        display: flex;
        position: relative;
        align-items: center;
        width: 100%;
        height: 100%;
    }
    #forum_threads .quickDetails {
        min-width: 21%;
        text-align: end;
    }
    #forum_threads .quickDetails a {
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: 0px 0.1em 0px 0px;
    }
    #forum_threads .quickDetails .nAnswers {
        font-weight: bold;
        font-size: 1.1rem;
        line-height: 0.7;
        margin: 0.2em 0px 0.1em 0px;
    }
    #forum_threads .quickDetails .author {
        font-size: 0.7rem;
        padding: 0em 0px 0.2em 0px;
    }
    #forum_threads .title {
        background-color: #B2B4BA;
        width: 70%;
    }
    #forum_threads .title a {
        display: flex;
        align-items: center;
        padding: 0.3em 0.2em 0.3em 0.2em;
        line-height: 1.1;
    }
    #forum_threads .delimiter {
        background-color: var(--color-black-2);
        height: 0.75rem;
        font-size: 0.7rem;
        color: white;
        text-align: center;
        font-weight: bold;
    }
    #forum_threads .delimiter p {
        line-height: 1.1;
    }
    #forum_comments {
        margin: 2rem 0px 0px 0px;
    }
    #forum_comments .header {
        background-color: var(--color-black-2);
        border-radius: 3px;
        color: white;
        position: relative;
        height: 2.2rem;
        z-index: 1;
    }
    #forum_comments .header .name {
        position: absolute;
        left: 6.1rem;
        top: 0.2rem;
        font-weight: bold;
        font-size: 0.92rem;
    }
    #forum_comments .header .stats {
        position: absolute;
        left: 6.1rem;
        top: 1.3rem;
        font-size: 0.7rem;
        opacity: 0.7;
    }
    #forum_comments .date {
        position: absolute;
        right: 0.4rem;
        display: inline;
        top: 0.3rem;
        font-size: 0.7rem;
        color: #9D9EA6;
    }
    #forum_comments .avatarDiv {
        width: 80px;
        max-height: 80px;
        position: absolute;
        top: 50%;
        left: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: black;
        transform: translate(-0%, -50%);
    }
    #forum_comments .avatar {
        max-width: 80px;
        max-height: 80px;
    }
    #forum_comments .body {
        background-color: white;
        margin: 0px 0.2rem 2.3rem 0.3rem;
        box-shadow: 0px 0px min(3px,0.2rem) 0px #00000088;
        padding: 0.6rem 0.5rem 0.4rem 5.9rem;
        font-size: 0.83rem;
        line-height: 1.15;
        transition: box-shadow 0.25s;
        position: relative;
        z-index: 0;
    }
    #forum_comments .body > .main {
        white-space: pre-wrap;
        min-height: 3rem;
        margin: 0rem 0px 0.25rem 0px;
    }
    #forum_comments .comment.new .body {
        box-shadow: 0px 0px min(7px,1.2rem) min(5px,0.4rem) #ffdd79a6;
    }
    #forum_comments .comment.selected {
        border: 2px dashed red;
    }
    #forum_comments .footer {
        font-size: 0.7rem;
        text-align: right;
        margin: 0.2em 0px 0px 0px;
    }
    #forum_comments .footer > *:not(:last-child) {
        margin: 0px 0px 0.2em 0px;
    }
    #forum_comments .footer .infos {
        color: gray;
        opacity: 0.8;
    }
    #forum_comments .footer .commActions > span {
        display: flex;
        align-items: center;
        gap: 0.3em;
    }
    #forum_comments .footer .commActions {
        display: flex;
        align-items: center;
        gap: 0.3em;
        justify-content: flex-end;
    }
    #forum_comments .footer .commActions a {
        display: inline-block;
        text-decoration: none;
        vertical-align: middle;
        padding: 0.2em 0px 0px 0px;
    }
    #searchForm {
        padding: 10px;
        margin-bottom: 10px;
        background-image: url({$res}/design/gripBg.png);
        background-repeat: repeat-x;
        border-radius: 4px;
        background-color: var(--color-black-2);
    }
    #searchForm .parameters {
        display: grid;
        grid-template-columns: 0.3fr 1fr;
        justify-items: start;
        align-items: center;
        row-gap: 0.2rem;
    }
    #searchForm .parameters label {
        font-weight: bold;
        color: white;
        font-size: 0.8rem;
        text-align: right;
        width: 100%;
        padding: 0px 0.5rem 0px 0px;
    }
    #searchForm .parameters input[type="radio"] {
        vertical-align: -0.1em;
        margin: 0px 0.1em 0px 0px;
    }
    #searchForm .buttons {
        margin: 0.5rem 0px 0px 0px;
    }
    #searchFormResults {
        line-height: 1.1;
    }
    #searchFormResults .searchItem {
        padding: 10px;
        margin: 5px 0px;
        background-image: url({$res}/design/gripBg.png);
        background-repeat: repeat-x;
        border-radius: 4px;
        background-color: #F4F3F2;
    }
    #searchFormResults .searchItem .infos {
        font-size: 0.8rem;
        border-bottom: 1px dashed black;
        padding: 0px 0px 0.5rem 0px;
    }
    #searchFormResults .searchItem .content {
        font-size: 0.8rem;
        margin: 0.5rem 0px 0.5rem 0px;
    }
    #searchFormResults .searchItem .footer {
        font-size: 0.8rem;
        color: grey;
        font-size: 0.7rem;
    }
    #mainDiv_forum .forum_mainBar {
        margin-bottom: 1rem;
        box-shadow: 0px 0px 0.2rem rgba(0,0,0,0.35);
    }
    #mainDiv_forum .forum_mainBar_sub1 {
        background-color: #B63B00;
        font-size: 1rem;
        color: white;
        font-weight: bold;
        padding: 0.1em 0.3em 0.1em 0.3em;
    }
    #mainDiv_forum .forum_mainBar_sub2 {
        display: flex;
        justify-content: space-between;
        background-color: #B63B00;
        opacity: 0.83;
        padding: 0.4rem;
    }
    #mainDiv_forum .subheader .infos1 {
        font-size: 0.8em;
    }
    #mainDiv_forum .subheader .infos2 {
        font-size: 0.7rem;
        text-align: center;
        border-bottom: 1px dotted black;
        padding: 0px 0px 0.4em 0px;
    }
    #mainDiv_forum .subheader .infos2 > .main {
        text-align: left;
        transition: all 0.5s;
        overflow: hidden;
        height: 1.1em;
        max-height: 1.1em;
        margin: 1em 0px 0px 0px;
    }
    #mainDiv_forum .subheader .infos2.hide > .main {
        height: 0%;
        margin: 0;
        max-height: 0;
    }
    #mainDiv_forum .hiddenFooter {
        font-size: 0.7rem;
        text-align: center;
        border-top: 1px dotted black;
        margin: 0.5rem 0px 0px 0px;
        padding: 0.4em 0px 0.4em 0px;
    }
    #mainDiv_forum .hiddenFooter > .main {
        text-align: left;
        transition: all 0.5s;
        overflow: hidden;
        /* max-height: 1.2em; */
    }
    #mainDiv_forum .hiddenFooter > .main ul {
        list-style: disc;
        margin: 0px 0px 0px 2em;
    }
    #mainDiv_forum .hiddenFooter.hidden > .main {
        height: 0%;
        margin: 0;
        max-height: 0;
    }
    #mainDiv_forum .forum_footer, #mainDiv_forum .pagDivDiv {
        display: flex;
        justify-content: space-between;
        background-color: var(--color-black-2);
        padding: 0.4rem;
    }
    #mainDiv_forum .forum_footer .actions, #mainDiv_forum .forum_mainBar .actions {
        flex: 1 2 60%;
    }
    #mainDiv_forum .forum_footer .paginationDiv, #mainDiv_forum .forum_mainBar .paginationDiv, #mainDiv_forum .pagDivDiv .paginationDiv {
        display: flex;
        justify-content: space-between;
        flex: 1 0 43%;
        align-items: center;
    }
    #mainDiv_forum .forum_footer .actions + .paginationDiv, #mainDiv_forum .forum_mainBar .paginationDiv {
        padding-left: 1.5%;
        border-left: 1px dashed black;
    }
    #mainDiv_forum .pagination_details {
        font-weight: initial;
        font-size: 0.7rem;
    }
    #mainDiv_forum .pagination_details:hover .nPage {
        border: 1px dashed white;
        box-sizing: border-box;
    }
    #mainDiv_forum .pagination_details .nPage {
        font-size: 1rem;
        vertical-align: -0.15rem;
        font-weight: bold;
    }
    #mainDiv_forum .pagination_details .maxPages {
        font-size: 0.7rem;
        opacity: 0.8;
        vertical-align: -0.05rem;
    }
    #mainDiv_forum .pagination_details input[type="text"]{
        width: 2.5rem;
        text-align: center;
        border: 0;
        box-shadow: inset 0px 2px 3px 0px black;
    }
    #mainDiv_forum .iconDiv {
        display: inline-flex;
        align-items: center;
        vertical-align: middle;
    }
    #mainDiv_forum .iconDiv .iconDiv_begin {
        cursor: pointer;
    }
    #mainDiv_forum .iconDiv .iconDiv_mid {
        background-image: url($res/design/like_bg.png);
        background-repeat: repeat-x;
        height: 20px;
        display: flex;
        align-items: center;
        position: relative;
    }
    #mainDiv_forum .iconDiv .iconDiv_mid .diffsDiv {
        position: absolute;
        top: -1em;
        left: -0.5ch;
        transform: translate(0%,-100%);
        display: flex;
        flex-direction: column-reverse;
        background-color: white;
    }
    #mainDiv_forum .iconDiv .iconDiv_mid p {
        padding: 0px 5px 0px 7px;
        font-size: 11px;
        line-height: 1.1;
    }
    #mainDiv_forum .octohitDiv .octohitDiv_mid p {
        color: darkRed;
        line-height: 1.1;
    }
    @media screen and (max-width: 800px) {
        #forumR, #forumL{
            max-width: 95%;
        }
        #mainDiv_forum .forum_footer, #mainDiv_forum .forum_mainBar_sub2 {
            flex-direction: column;
            gap: 0.5rem;
        }
        #mainDiv_forum .forum_footer .actions + .paginationDiv, #mainDiv_forum .forum_mainBar .paginationDiv {
            padding: 0;
            border: 0;
            padding-top: 1.5%;
            border-top: 1px dashed black;
        }
        #mainDiv_forum .replyFormDiv .replyForm .buttonBar {
            flex-direction: column;
            gap: 0.3em;
        }
        #forum_comments .avatar {
            max-width: 60px;
            max-height: 60px;
        }
        #forum_comments .avatarDiv {
            width: 60px;
            max-height: 60px;
        }
        #forum_comments .header .name {
            left: 4.9rem;
        }
        #forum_comments .header .stats {
            left: 4.9rem;
        }
        #forum_comments .body {
            padding: 0.6rem 0.5rem 0.4rem 1.2rem;
        }
        #forum_comments .body > .main > p:first-of-type {
            text-indent: 3.7rem;
        }
        #forum_threads tbody tr[data-selected="true"] .selectArrow {
            display: none;
        }
    }
    CSS];
}

function getVersionHistoryElem() {
    global $isAuth;
    return ['html' => <<<HTML
    <div id="mainDiv_versionHistory" class="authPadded" data-is-auth="$isAuth">
        <div id="versions">
            <button>0.1</button><!--
            --><button>0.1b</button><!--
            --><button>0.2</button><!--
            --><button>0.3</button>
        </div>
        <div id="versionDescription"></div>
    </div>

    HTML, 'js' => <<<JAVASCRIPT
    const buttons = document.querySelectorAll('#versions button');
    const div = document.querySelector('#versionDescription');
    for (const b of buttons) b.addEventListener('click', () => {
        for (const bb of buttons) bb.setAttribute('selected','0');
        b.setAttribute('selected',1);
        loadDesc(b.innerText);
    });   
    function loadDesc(s) {
        switch (s) {
            case '0.1':
                div.innerHTML = `
                    <h2>Version 0.1 <span>Sortie : 1 Août 2023</span></h2>
                    <p class="subheader">— La première version. Recréation partielle du système de forum Twinoid et autres fonctionnalités basiques pour commencer. Incomplet, pas bien testé et un peu moche, mais c'est un bon début.</p>
                    <div class="main">
                        <section>
                            <h3>Forum</h3>
                            <section>
                                <h4>Récapitulatif des fonctionnalités</h4>
                                <ul>
                                    <li>Création de topics et commentaires.</li>
                                    <li>Stylisations/fonctionnalités des posts: Gras, Italique, Barré, Insertions de liens, Citations, Spoil.</li>
                                    <li>Suivis de topics et réceptions de notifications.</li>
                                    <li>Émoticônes Twinoid disponibles en fonction de l'utilisateur (voir <a href="https://twinoid.com/tid/forum#!view/161558%7Cthread/66116573" target="_blank">ce topic</a> pour importer vos émojis).</li>
                                    <li>Fonction recherche trèèès basique (uniquement pour les topics sauvegardés de Twinoid pour l'instant et pas formaté).</li>
                                    <li>API publique GraphQL disponible via l'url https://api.siteinteressant.fr/graphql</li>
                                </ul>
                            </section>
                        </section>
                        <section>
                            <h3>Comptes utilisateurs</h3>
                            <section>
                                <h4>Récapitulatif des fonctionnalités</h4>
                                <ul>
                                    <li>Créations de comptes via codes d'invitations</li>
                                    <li>Changements d'avatars (pour l'instant seulement les fichiers gif, png et jpg sont autorisés)</li>
                                    <li>2 titres disponibles : Créateur, Ancien Intéressant</li>
                                </ul>
                            </section>
                            <section>
                                <h4>Titres d'utilisateur</h4>
                                <p>Les titres donnent(ou retirent) des pouvoirs aux utilisateurs.</p>
                                <ul>
                                    <li><b>Créateur : </b> Pleins pouvoirs.</li>
                                    <li><b>Ancien Intéressant : </b> Accès aux topics Twinoid.</li>
                                </ul>
                            </section>
                        </section>
                    </div>`.trim();
                break;
            case '0.1b':
                div.innerHTML = `
                    <h2>Version 0.1b <span>Sortie : 2 Août 2023 — 5 Août 2023</span></h2>
                    <p class="subheader">— La deuxième version, qui est la version utilisable et plus ergonomique de la première version.</p>
                    <div class="main">
                        <section>
                            <h3>Bugfixs</h3>
                            <section>
                                <h4>Récapitulatif</h4>
                                <ul>
                                    <li>Utilisable sous Firefox.</li>
                                    <li>Les topics et le nombre de pages disponibles apparaissent correctement.</li>
                                    <li>La création et recherche de topics est faisable sur les petits écrans.</li>
                                    <li>Les notifications se mettent en "lu" et disparaissent correctement.</li>
                                    <li>Les stylisations s'affichent correctement dans les messages postés.</li>
                                    <li>La limite de taille d'un avatar passe de 20kb à 20mb.</li>
                                    <li>La pagination n'est plus buggée si vous tentez d'aller à des pages inexistantes.</li>
                                    <li>La balise [cite] peut prendre un argument comme dans Twinoid. (Rétroactif, les messages postés avec un argument avant cette version s'afficheront correctement.)</li>
                                    <li>L'aperçu de message se mets à jour correctement.</li>
                                    <li>Meilleure apparence sur les petits écrans.</li>
                                    <li>L'édition de message permet plus de façons d'écrire un message.</li>
                                    <li>Le bouton retour apparait dans plus de situations.</li>
                                    <li>Votre pseudo est affiché dans la barre, au lieu de juste "Sanart".</li>
                                </ul>
                            </section>
                        </section>
                        <section>
                            <h3>Autres améliorations</h3>
                            <section>
                                <h4>Récapitulatif</h4>
                                <ul>
                                    <li>Possibilité d'utiliser Tab/Shift+Tab pour créer des topics plus rapidement.</li>
                                    <li>Quand vous créez un topic ou un commentaire le champ sera sélectionné automatiquement.</li>
                                    <li>La barre de droite se ferme automatiquement plus tôt.</li>
                                    <li>Et autres ajustements visuels</li>
                                </ul>
                            </section>
                        </section>
                    </div>`.trim();
                    break;
            case '0.2':
                div.innerHTML = `
                    <h2>Version 0.2 <span>Sortie : 28 Août 2023</span></h2>
                    <p class="subheader">— Amélioration du forum, maintenant on peut voir les messages non-lus et c'est plus beau à voir. Il reste plus grand chose pour qu'il soit meilleur que celui de Twinoid.</p>
                    <div class="main">
                        <section>
                            <h3>Forum</h3>
                            <section>
                                <h4>Nouvelles fonctionnalités</h4>
                                <ul>
                                    <li>Ajout des marqueurs pour voir les topics et messages lus et non lus.</li>
                                    <li>Ouvrir un topic redirige automatiquement au dernier message non-lu ou à la dernière page.</li>
                                    <li>Les messages que vous écrivez ne sont plus forcément effacés si vous quittez ou rafraichissez la page.</li>
                                    <li>Ajout des balises roleplay et code.</li>
                                </ul>
                                <h4>Bugfixs</h4>
                                <ul>
                                    <li>Des smileys qui ne s'affichaient pas s'affichent maintenant. (rétroactif)</li>
                                    <li>Les insertions de smileys non-reconnus ne génèrent plus d'erreur.</li>
                                    <li>L'apparence visuelle du forum a été améliorée.</li>
                                </ul>
                            </section>
                        </section>
                    </div>`.trim();
                break;
            case '0.3':
                div.innerHTML = `
                    <h2>Version 0.3 <span>Sortie : 19 Septembre 2023</span></h2>
                    <p class="subheader">— On peut enfin kuber, citer, éditer et supprimer. La fonction recherche fonctionne mieux et le développement du site peut être suivi via Github. Le chiffre 4 porte malheur donc la prochaine version sera la 1.0. Faisons une super 1.0 avant la disparition de Twinoid qui a été annoncé pour début Novembre...</p>
                    <div class="main">
                        <section>
                            <h3>Forum</h3>
                            <section>
                                <h4>Quoi de neuf ?</h4>
                                <ul>
                                    <li>La fonction recherche a été grandement améliorée.</li>
                                    <li>Ajout du bouton citer, éditer et supprimer sur les messages.</li>
                                    <li>Vous pouvez kuber et voir qui a kubé.</li>
                                    <li>Ajout d'un bouton pour échapper les caractères spéciaux automatiquement lors d'une action "coller".</li>
                                    <li>Et autres bugfixs et améliorations.</li>
                                </ul>
                            </section>
                        </section>
                        <section>
                            <h3>Github</h3>
                            <section>
                                <h4>Code disponible</h4>
                                <p>Le code du site est maintenant disponible sur Github à cette adresse : <a href="https://github.com/Sanart99/SiteInteressant">https://github.com/Sanart99/SiteInteressant</a>. (Vous verrez une erreur 404 tant que vous n'avez pas la permission de le voir.)</p>
                                <p>C'est pas documenté et vous aurez très probablement besoin d'explications pour comprendre comment tout colle mais je le rends dispo quand même avant d'avoir fini de tout nettoyer.</p>
                                <p>Si vous voulez proposer des fonctionnalités ou signaler un bug vous pouvez créer un "problème" dans Github pour chaque proposition/bug pour que ce soit bien visible. Un topic dédié peut suffir bien sûr, on pourra ensuite reporter ça dans Github.</p>
                            </section>
                        </section>
                        <section>
                            <h3>Autres améliorations</h3>
                            <section>
                                <h4>Messages d'erreurs</h4>
                                <p>A partir de maintenant les messages d'erreur "Erreur Interne" sont beaucoup plus rare et vous aurez plutôt quelque chose de plus descriptif.</p>
                                <p>Si vous tombez encore sur ce genre d'erreurs pendant une utilisation normale du site hésitez pas à me le dire car maintenant c'est très probablement quelque chose que j'ai zappé.</p>
                            </section>
                        </section>
                    </div>`.trim();
                break;
        }
    }
    buttons[buttons.length-1].click();

    JAVASCRIPT,
    'css' => <<<CSS
    #mainDiv_versionHistory {
        background: var(--bg-gradient-1);
        min-height: inherit;
        overflow: auto;
    }
    #mainDiv_versionHistory h2 + .subheader{
        font-size: 0.9rem;
        margin: 0.2em 0px 0px 0px;
    }
    #mainDiv_versionHistory p {
        line-height: 1.2em;
    }
    #mainDiv_versionHistory ul {
        list-style: disc;
        margin: 0.3em 0px 0.3em 2em;
        line-height: 1.2em;
    }
    #versions {
        display: flex;
        justify-content: center;
        margin: 1.5rem 0px;
        padding: 0.3rem 0px;
        border-top: 1px dashed black;
        border-bottom: 1px dashed black;
        gap: 0.5rem;
    }
    #versions button {
        background-color: var(--color-black-2);
        border: 0;
        border-radius: 0.2em;
        padding: 0.2em 0.8em;
        color: white;
        font-weight: bold;
    }
    #versions button[selected="1"] {
        background-color: var(--color-orange-2);
    }
    #versionDescription {
        margin: 2rem 8%;
    }
    #versionDescription .main {
        margin: 1rem 0px 0px 0px;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    #versionDescription h2 {
        color: var(--color-orange-text-1);
        font-weight: bold;
        font-size: 1.8rem;
    }
    #versionDescription h2 span {
        color: grey;
        font-size: 0.6em;
    }
    #versionDescription h3 {
        color: var(--color-black-2);
        font-size: 1.6rem;
        border-bottom: 1px solid black;
        margin: 0px 0px 0.7em 0px;
        padding: 0px 0px 0.1em 0px;
    }
    #versionDescription h4 {
        font-size: 1.25rem;
        margin: 1em 0px 0.3em 0px;
    }
    #versionDescription h3 ~ section > p {
        margin: 0.5em 0px;
    }
    CSS];
}

function getUserSettings() {
    global $isAuth;
    return ['html' => <<<HTML
    <div id="mainDiv_userSettings" class="authPadded" data-is-auth="$isAuth">
        <form id="settingsForm" class="main">
            <section>
                <h2>Forum</h2>
                <div class="sectionContent">
                    <ul>
                        <li><input id="settings_forum_autoMarkPagesAsRead" name="forum_autoMarkPagesAsRead" type="checkbox" disabled><label for="settings_forum_autoMarkPagesAsRead">Automatiquement marquer les pages comme lu</label></li>
                        <li><input id="settings_forum_followThreadsOnComment" name="forum_followThreadsOnComment" type="checkbox" disabled><label for="settings_forum_followThreadsOnComment">Automatiquement suivre un topic après l'avoir commenté</label></li>
                    </ul>
                </div>
            </section>
            <section>
                <h2>Notifications</h2>
                <div class="sectionContent">
                    <ul>
                        <li>
                            <input id="settings_notif" name="notifications" type="checkbox" disabled><label for="settings_notif" class="bold">Activer les notifications push</label>
                            <!-- <ul>
                                <li><input id="settings_device_notif" class="local" type="checkbox" disabled><label for="settings_device_notif">Activer pour cet appareil</label></li>
                            </ul> -->
                            <ul>
                                <br/>
                                <p>Envoyer une notification push quand :</p>
                                <li><input id="settings_notif_newThread" name="notif_newThread" type="checkbox" disabled><label for="settings_notif_newThread">Un nouveau topic est créé</label></li>
                                <li><input id="settings_notif_newCommentOnFollowedThread" name="notif_newCommentOnFollowedThread" type="checkbox" disabled><label for="settings_notif_newCommentOnFollowedThread">Un topic que vous suivez a un nouveau commentaire</label></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </section>
            <input id="settings_submit" type="submit" value="Enregistrer" disabled/>
        </form>
    </div>

    HTML,'js' => <<<JAVASCRIPT
    const settingsForm = document.querySelector('#settingsForm');
    const allInputs = document.querySelectorAll('#mainDiv_userSettings input');
    const allSettings = document.querySelectorAll('#mainDiv_userSettings .sectionContent input');
    const globalSettings = document.querySelectorAll('#mainDiv_userSettings .sectionContent input:not(.local)');

    const eForum_MarkPagesAsRead = document.querySelector('#settings_forum_autoMarkPagesAsRead');
    const eForum_followThreadsOnComment = document.querySelector('#settings_forum_followThreadsOnComment');
    const eNotif = document.querySelector('#settings_notif');
    // const eDeviceNotif = document.querySelector('#settings_device_notif');
    const eNotifNewThread = document.querySelector('#settings_notif_newThread');
    const eNotifNewCommentOnFollowedThread = document.querySelector('#settings_notif_newCommentOnFollowedThread');

    toggleInputs(false);
    initAfterSettingsSync();

    function initAfterSettingsSync() {
        if (__settingsInitialized) {
            loadInputVals();
            eNotif.addEventListener('change',() => { if (eNotif.checked) Notification.requestPermission(); });
            for (const e1 of allSettings) e1.addEventListener('change',() => {
                for (const e2 of e1.parentElement.querySelectorAll('input')) if (e2 != e1) e2.disabled = !e1.checked;
            });
            toggleInputs(true);
        }
        else setTimeout(initAfterSettingsSync,250);
    }
    function toggleInputs(enable) {
        for (const e of allInputs) e.disabled = !enable;
        // if (!__feat_notifications) eDeviceNotif.disabled = true;

        if (enable) {
            // Disable children elements if parent unchecked
            for (const e1 of allSettings) if (e1.disabled) for (const e2 of e1.parentElement.querySelectorAll('input')) {
                if (e2 != e1) e2.disabled = true;
            }
        }
    }
    function loadInputVals() {
        eForum_MarkPagesAsRead.checked = localGet('settings_forum_autoMarkPagesAsRead') === 'true';
        eForum_followThreadsOnComment.checked = localGet('settings_forum_followThreadsOnComment') === 'true';

        eNotif.checked = localGet('settings_notifications') === 'true';
        // eDeviceNotif.checked = localGet('settings_device_notifications') === 'true' && __feat_notifications;
        eNotifNewThread.checked = localGet('settings_notif_newThread') === 'true';
        eNotifNewCommentOnFollowedThread.checked = localGet('settings_notif_newCommentOnFollowedThread') === 'true';
    }
    
    settingsForm.addEventListener('submit',(e) => {
        e.preventDefault();
        toggleInputs(false);

        function saveLocalSettings() {
            // localSet('settings_device_notifications', eDeviceNotif.checked);
        }

        if (eNotif.checked) {
            if (Notification.permission !== 'granted') {
                alert('You didn\'t grant notification permission.');
                Notification.requestPermission();
                toggleInputs(true);
                return;
            }
            initPushSubscription();
        }

        if (__online) {
            const vals = [];
            for (const e of globalSettings) vals.push({name:e.name, value:e.checked ? '1' : '0'});

            sendQuery(`mutation ChangeSetting(\$vals:[SettingInput!]!) {
                changeSetting(vals:\$vals) {
                    success
                    resultCode
                    resultMessage
                }
            }`,{vals:vals}).then((json) => {
                if (!basicQueryResultCheck(json?.data?.changeSetting,true)) {
                    toggleInputs(true);
                    return;
                }

                saveLocalSettings();
                loadGlobalSettings().then(() => {                    
                    syncSettingsWithServiceWorker();
                    toggleInputs(true);
                });
                alert('Paramètres sauvegardés.');
            });
        } else {
            saveLocalSettings();
            syncSettingsWithServiceWorker();
            alert('Paramètres sauvegardés.');
            toggleInputs(true);
        }
    });

    if (!globalMap.has('ev_settings_conn')) {
        globalMap['ev_settings_conn'] = true;
        addEventListener('offline',() => {
            loadInputVals();
            toggleInputs(true);
            for (const e of globalSettings) { e.disabled = true; }
        });
        addEventListener('online',() => {
            for (const e of globalSettings) { e.disabled = false; }
        });
    }

    JAVASCRIPT, 'css' => <<<CSS
    #mainDiv_userSettings {
        background: var(--bg-gradient-1);
        min-height: inherit;
        overflow: auto;
    }
    #mainDiv_userSettings > .main {
        margin: 2rem 8%;
    }
    #mainDiv_userSettings h2 {
        color: var(--color-black-2);
        font-size: 1.6rem;
        border-bottom: 1px solid black;
        margin: 1em 0px 0.7em 0px;
        padding: 0px 0px 0.1em 0px;
    }
    #mainDiv_userSettings ul li {
        margin: 0.5em 0px;
    }
    #mainDiv_userSettings ul > li > ul {
        margin: 0px 0px 0px 1em;
    }
    #mainDiv_userSettings input[type="checkbox"] {
        margin: 0px 0.5em 0px 0px;
    }
    #mainDiv_userSettings .sectionContent > ul > li > label.bold {
        font-weight: bold;
    }
    #mainDiv_userSettings input[type="submit"] {
        background-color: var(--color-black-2);
        border: 0;
        border-top: 1px solid #6C7188;
        outline: 1px solid var(--color-black-1);
        padding: 0.2rem 0.4rem 0.2rem 0.4rem;
        color: white;
        font-size: 0.7rem;
        font-weight: bold;
    }
    #mainDiv_userSettings input[type="submit"]:hover {
        background-color: #3b4151;
        border-color: var(--color-black-1);
    }
    
    CSS
];
}
?>