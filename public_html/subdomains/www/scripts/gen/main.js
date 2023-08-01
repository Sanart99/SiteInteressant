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
                <p class="username">Sanart</p>
                <img class="avatar" src="$res/avatars/empty.jpg" />
            </a>
        </div>
    </div>
    <div id="rightBar" style="display:none">
        <div id="rightBar_titleDiv">
            <p>Sanart</p>
        </div>
        <div id="rightBar_optionsDiv">
            <a id="rightBar_optionsDiv_editAvatar" href="#" onclick="return false;"><p>Changer d'avatar</p></a>
            <a id="rightBar_optionsDiv_forum" href="$root/forum"><p>Forum</p></a>
            <a href="$root/versionhistory"><p>Historique versions</p></a>
            <a id="rightBar_optionsDiv_disconnect" href="#" onclick="return false;"><p>Se Déconnecter</p></a>
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
    function getRecentEvents() {
        if (gettingEvents) return; else gettingEvents = true;
        const notifCont = rightBar.querySelector('#rightBar_recentEvents');
        const histCont = rightBar.querySelector('#rightBar_history > div');
        notifCont.innerHTML = '<p>Loading...</p>';
        sendQuery(`query {
            viewer {
                dbId
                notifications(first:50) {
                    edges {
                        node {
                            dbId
                            date
                            notificationGroup
                            actionName
                            isRead
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
                            }
                        }
                    }
                }
            }
        }`).then((res) => {
            if (!res.ok) basicQueryError();
            return res.json();
        }).then((json) => {
            if (json?.data?.viewer?.notifications?.edges == null) basicQueryError();
            gettingEvents = false;
            const notifications = json.data.viewer.notifications;
            const userId = json.data.viewer.dbId;
            notifCont.innerHTML = '';
            histCont.innerHTML = '';

            let recentEventsN = 0;
            function setRecentEventsN(n) {
                const e = topBar.querySelector('#topBar_r_recentEvents');
                if (n > 0) {
                    e.style.display = '';
                    const ss = n > 1 ? 'nouveaux évènements' : 'nouvel évènement';
                    e.innerHTML = `<p>\${n} \${ss}</p>`;
                } else e.style.display = 'none';  
            }

            const history = {};
            const newComments = {};            
            const ignoreThreadComments = new Set();
            let lastEvent = {first:null, name:'', n:0};
            lastEvent.flush = () => {
                if (lastEvent.first?.notificationGroup == null) return;
                else if (lastEvent.first.notificationGroup == 'FORUM') {
                    const notif = lastEvent.first;
                    switch (notif.actionName) {
                        case 'addComment':
                            const sComm = lastEvent.n > 1 ? 'nouveaux commentaires' : 'nouveau commentaire';
                            const node = stringToNodes(`<a href="$root/forum/\${notif.thread.dbId}" onclick="return false;" class="historyItem">
                                <p class="title">\${notif.thread.title}</p>
                                <p class="description">\${lastEvent.n} \${sComm}</p>
                            </a>`)[0];
                            node.addEventListener('click',() => loadPage(node.href,StateAction.PushState));
                            histCont.insertAdjacentElement('afterbegin',node);
                            break;
                    }
                }
            }

            for (const edge of notifications.edges) {
                const notif = edge.node;
                switch (notif.actionName) {
                    case 'addComment':
                        if (lastEvent.name == 'thAddComment_'+notif.thread.dbId) lastEvent.n++;
                        else {
                            lastEvent.flush();
                            lastEvent.first = notif;
                            lastEvent.name = 'thAddComment_'+notif.thread.dbId;
                            lastEvent.n = 1;
                        }
                        
                        if (ignoreThreadComments.has(notif.thread.dbId)) continue;
                        if (notif.isRead) { ignoreThreadComments.add(notif.thread.dbId); continue; }
                        if (newComments[notif.thread.dbId] == null) newComments[notif.thread.dbId] = {n:0, notifId:notif.dbId, thread:notif.thread, users:new Set()};
                        const o = newComments[notif.thread.dbId];
                        o.n++;
                        o.users.add(notif.comment.author.name);
                        break;
                }
            }
            lastEvent.flush();
            
            for (const dbId in newComments) {
                const o = newComments[dbId];
                const s = o.n > 1 ? 'nouveaux commentaires' : 'nouveau commentaire';
                const node = stringToNodes(`<a href="$root/forum/\${o.thread.dbId}" onclick="return false" class="notification new">
                    <div class="notification_type">

                    </div>
                    <div class="notification_content">
                        <p class="title">\${o.thread.title}</p>
                        <p class="desc">\${o.n} \${s} (\${Array.from(o.users).join(',')})</p>
                    </div>
                </a>`)[0];
                let timeout = null;
                node.addEventListener('click',() => {
                    node.classList.remove('new');
                    loadPage(node.href,StateAction.PushState).then(() => {
                        sendQuery(`mutation SetNotificationToRead(\$userId:Int!,\$notifId:String!) {
                            f:setNotificationToRead(userId:\$userId,notifId:\$notifId) {
                                __typename
                                success
                                resultCode
                                resultMessage
                            }
                        }`,{userId:userId,notifId:o.notifId}).then((res) => {
                            if (!res.ok) basicQueryError();
                            return res.json();
                        }).then((json) => {
                            if (json?.data?.f?.success == null) basicQueryError();
                            if (!json.data.f.success) return;
                            node.classList.remove('new');
                            setRecentEventsN(--recentEventsN);
                        });
                    });
                });
                node.addEventListener('mouseleave',() => { if (timeout != null) clearTimeout(timeout); timeout = null; });
                notifCont.insertAdjacentElement('beforeend',node);             
            }
            
            for (const k in newComments) if (newComments.hasOwnProperty(k)) ++recentEventsN;
            setRecentEventsN(recentEventsN);
        });
    }

    const topBarRSlideArea = document.querySelector('#topBar_r_slideArea');
    topBarRSlideArea.addEventListener('click',openRightBar);
    let tbrTimeout = null;
    topBarRSlideArea.addEventListener('mouseenter',() => { tbrTimeout = setTimeout(openRightBar, 400); });
    topBarRSlideArea.addEventListener('mouseleave',() => { if (tbrTimeout != null) clearTimeout(tbrTimeout); });
    let rbTimeout = null;
    rightBar.addEventListener('mouseenter',() => { if (rbTimeout != null) clearTimeout(rbTimeout); });
    rightBar.addEventListener('mouseleave',() => { rbTimeout = setTimeout(closeRightBar, 300); });

    document.querySelector('#rightBar_optionsDiv_editAvatar').addEventListener('click',(e) => {
        e.preventDefault();
        const node = stringToNodes(`$getEditAvatarHTML`)[0];
        popupDiv.insertAdjacentElement('beforeend',node);
        $getEditAvatarJS
        popupDiv.openTo('#editAvatar');
    });  
    document.querySelector('#rightBar_optionsDiv_forum').addEventListener('click',(e) => {
        e.preventDefault();
        loadPage("$root/forum",StateAction.PushState);
    });    
    document.querySelector('#rightBar_optionsDiv_disconnect').addEventListener('click',() => {
        popupDiv.insertAdjacentHTML('beforeend',`$getDisconnectElemHTML`);
        $getDisconnectElemJS
        popupDiv.openTo('#askDisconnect');
    });

    if ($isAuth === 1) {
        topBar.style.display = rightBar.style.display = '';

        sendQuery(`query { viewer { avatarURL } }`).then((res) => {
            if (!res.ok) basicQueryError();
            return res.json();
        }).then((json) => {
            if (json?.data?.viewer?.avatarURL == null) basicQueryError();
            document.querySelector('#topBar_r_slideArea .avatar').src = json.data.viewer.avatarURL;
        });

        getRecentEvents();
        setInterval(() => { if (rightBar.getAttribute('open') != 1) getRecentEvents(); },15000);
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
    #rightBar_optionsDiv {
        display: flex;
        flex-direction: column;
        align-items: center;
        background-color: var(--color-black-1);
    }
    #rightBar_optionsDiv a {
        display: flex;
        height: 2rem;
        width: 100%;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }
    #rightBar_optionsDiv a:hover {
        background-color: var(--color-orange-1);
        color: white;
    }
    #rightBar_notificationsDiv {
        height: 100%;
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
        <p>HOME.PHP</p>
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
    global $isAuth,$root;
    return ['html' => <<<HTML
    <div id="mainDiv_forum" class="authPadded" data-is-auth="$isAuth">
        <div id="forum_content">
            <div id="forumL">
                <div class="forum_mainBar">
                    <div class="forum_mainBar_sub1"><p>Asile Intéressant</p></div>
                    <div class="forum_mainBar_sub2">
                        <div>
                            <button class="searchLoader button1" type="button"><img src="https://data.twinoid.com/img/icons/search.png"/></button><!--
                            --><button class="refreshThreads button1" type="button"><img src="https://data.twinoid.com/img/icons/refresh.png"/></button><!--
                            --><button class="newThreadLoader button1" type="button"><img src="https://data.twinoid.com/img/icons/edit.png"/>Créer un topic</button>
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
                        <tr>
                            <td class="statusIcons"><a href="#" onclick ="return false;"></a></td>
                            <td class="title"><a href="#" onclick ="return false;"><p>Titre</p></a></td>
                            <td class="quickDetails"><a href="#" onclick ="return false;"><p class="nAnswers">20</p><p class="author">Someone</p></a></td>
                        </tr>
                        <tr>
                            <td colspan="100" class="delimiter">Delimiter</td>
                        </tr> 
                    </tbody>
                </table>
                <div class="forum_footer">
                    <div class="paginationDiv">
                        <div>
                            <button class="button1 first" type="button"><img src="https://data.twinoid.com/img/icons/first.png"/></button><!--
                            --><button class="button1 left" type="button"><img src="https://data.twinoid.com/img/icons/left.png"/></button>
                        </div>
                        <div>
                            <button class="button1 pagination_details" type="button">
                                <p>Page <span class="nPage">?</span> <span class="maxPages">/ <span class="maxPages">?<span class="nMaxPages"></span></p>
                            </button>
                        </div>
                        <div>
                            <button class="button1 right" type="button"><img src="https://data.twinoid.com/img/icons/right.png"/></button><!--
                            --><button class="button1 last" ttype="button"><img src="https://data.twinoid.com/img/icons/last.png"/></button>
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
        }`,{first:first,last:last,after:after,before:before,skipPages:skipPages}).then((res) => {
            if (!res.ok) basicQueryError();
            return res.json();
        }).then((json) => {
            if (json?.data?.forum?.threads?.edges == null) basicQueryError();
            const tBody = document.querySelector('#forum_threads tbody');
            const threads = json.data.forum.threads;
            tBody.innerHTML = '';
            const now = new Date();
            let lastDelimiter = '';
            for (const edge of threads.edges) {
                const comment = edge.node.comments.edges[0].node;
                const date = new Date(edge.node.lastUpdateDate);
                if (!isNaN(date.getTime())) {
                    const sDate = getDateAsString(date).join(' ');
                    if (date.toISOString().substr(0,10) == now.toISOString().substr(0,10) && lastDelimiter != "Aujourd'hui") {
                        tBody.insertAdjacentHTML('beforeend',`<tr><td colspan="100" class="delimiter">Aujourd'hui</td></tr>`);
                        lastDelimiter = "Aujourd'hui";
                    } else if (lastDelimiter != sDate) {
                        tBody.insertAdjacentHTML('beforeend',`<tr><td colspan="100" class="delimiter">\${sDate}</td></tr>`);
                        lastDelimiter = sDate;
                    }
                } else if (lastDelimiter != 'Date inconnue') {
                    tBody.insertAdjacentHTML('beforeend',`<tr><td colspan="100" class="delimiter">Date inconnue</td></tr>`);
                    lastDelimiter = 'Date inconnue';
                }

                const tr = stringToNodes(`<tr data-node-id="\${edge.node.id}">
                    <td class="statusIcons"><a href="#" onclick ="return false;"><div><img class="selectArrow" src="https://data.twinoid.com/img/icons/selected.png"/></div></a></td>
                    <td class="title"><a href="#" onclick ="return false;"><p>\${edge.node.title}</p></a></td>
                    <td class="quickDetails"><a href="#" onclick ="return false;"><p class="nAnswers">\${comment.number}</p><p class="author">\${comment.author.name}</p></a></td>
                </tr>`)[0];
                tBody.insertAdjacentElement('beforeend',tr);
                for (const e of tr.querySelectorAll('a')) {
                    e.addEventListener('click',() => loadThread(edge.node.id,10,null,null,null,0,true));
                }
            }

            const eNPage = document.querySelector('#forumL .nPage');
            const eMaxPages = document.querySelector('#forumL .maxPages');
            eNPage.innerHTML = threads.pageInfo.currPage;
            eMaxPages.innerHTML = `/ <span class="nMaxPages">\${threads.pageInfo.pageCount}</span>`;
            document.querySelector('#forumL .forum_footer .left').dataset.cursor = threads.pageInfo.startCursor;
            document.querySelector('#forumL .forum_footer .right').dataset.cursor = threads.pageInfo.endCursor;
            
            highlightThread(currThreadId);
        });
    }
    function loadThread(threadId,first,last,after,before,skipPages=0,pushState=false) {
        sendQuery(`query (\$threadId:ID!,\$first:Int,\$last:Int,\$after:ID,\$before:ID,\$skipPages:Int!) {
            viewer {
                dbId
            }
            node(id:\$threadId) {
                __typename
                id
                ... on Thread {
                    dbId
                    title
                    followingIds
                    comments(first:\$first,after:\$after,before:\$before,last:\$last,skipPages:\$skipPages,withPageCount:true,withLastPageSpecialBehavior:true) {
                        edges {
                            node {
                                id
                                threadId
                                number
                                creationDate
                                lastEditionDate
                                content
                                author {
                                    id
                                    dbId
                                    name
                                    avatarURL
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
        }`,{threadId:threadId,first:first,last:last,after:after,before:before,skipPages:skipPages}).then((res) => {
            if (!res.ok) basicQueryError();
            return res.json();
        }).then((json) => {
            if (json?.data?.node?.comments?.edges == null) basicQueryError();
            currThreadId = threadId;
            highlightThread(currThreadId);

            forumR.innerHTML = '';
            if (mobileMode) { forumL.style.display = 'none'; forumR.style.display = ''; }
            if (forumR.querySelector('.forum_mainBar') == null) {
                const e = stringToNodes(`<div class="forum_mainBar">
                    <div class="forum_mainBar_sub1"><p>\${json.data.node.title}</p></div>
                    <div class="forum_mainBar_sub2">
                        <div class="actions"></div>
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
                    const paginationDiv = setupPagInput(null,() => loadThread(threadId,10),() => loadThread(threadId,null,10),
                        (n,cursor,skipPages) => loadThread(threadId,null,n,null,cursor,skipPages),
                        (n,cursor,skipPages) => loadThread(threadId,n,null,cursor,null,skipPages)
                    );
                    cont.insertAdjacentElement('beforeend', paginationDiv);
                }
            }

            const eComments = document.querySelector('#forum_comments');
            const comments = json.data.node.comments;
            eComments.innerHTML = '';
            currThreadId = threadId;
            for (const comment of comments.edges) {
                eComments.insertAdjacentHTML('beforeend',`<div class="comment">
                    <div class="header">
                        <div class="avatarDiv">
                            <img class="avatar" src="\${comment.node.author.avatarURL}" />
                        </div>
                        <p class="name">\${comment.node.author.name}</p>
                        <p class="date">\${new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle:'medium'}).format(new Date(comment.node.creationDate+'Z'))}</p>
                    </div>
                    <div class="body">
                        \${comment.node.content}
                    </div>
                </div>`);
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

            const replyFormDiv = stringToNodes(`<div class="replyFormDiv hide">
                <a class="previewToggler" href="#" onclick="return false;">Masquer / afficher l'aperçu de votre message</a>
                <div class="preview">

                </div>
                <form class="replyForm">
                    <div class="buttonBar">
                        <button class="button1 bold" type="button">G</button><!--
                        --><button class="button1 italic" type="button">I</button><!--
                        --><button class="button1 strike" type="button">Barré</button><!--
                        --><button class="button1 link" type="button">Lien</button><!--
                        --><button class="button1 cite" type="button">Citer</button><!--
                        --><button class="button1 spoil" type="button">Spoil</button>
                    </div>
                    <textarea name="msg"></textarea>
                    <div class="emojisDiv">
                        <div class="emojisButtons"></div>
                        <div class="emojis"></div>
                    </div>
                    <input class="button2" type="submit" value="Envoyer"/>
                </form>
            </div>`)[0];
            forumR.insertAdjacentElement('beforeend',replyFormDiv);
            setupReplyForm(replyFormDiv,(e) => {
                e.preventDefault();
                const data = new FormData(e.target);
                const submitButton = e.target.querySelector('input[type="submit"]');
                if (submitButton.disabled === true) return;
                submitButton.disabled = true;

                sendQuery(`mutation ForumAddComment(\$threadId:Int!,\$msg:String!) {
                    f:forumThread_addComment(threadId:\$threadId,content:\$msg) {
                        __typename
                        success
                        resultCode
                        resultMessage
                    }
                }`,{threadId:json.data.node.dbId,msg:data.get('msg')}).then((res) => {
                    if (!res.ok) basicQueryError();
                    return res.json();
                }).then((json) => {
                    if (json?.data?.f?.success == null) basicQueryError();
                    loadThread(threadId,0,10);
                });
            });

            const actionsCont = document.querySelectorAll('#forumR .actions');
            for (const cont of actionsCont) {
                cont.innerHTML = '';
                const back = stringToNodes('<button class="button1 back" type="button"><img src="https://data.twinoid.com/img/icons/back.png"/></button>')[0];
                cont.insertAdjacentElement('beforeend',back);
                back.addEventListener('click', () => {
                    if (!mobileMode) return;
                    forumR.style.display = 'none';
                    forumL.style.display = '';
                });
                back.style.display = mobileMode ? '' : 'none';
                const reply = stringToNodes('<button class="button1 reply" type="button"><img src="https://data.twinoid.com/img/icons/edit.png"/>Répondre</button>')[0];
                cont.insertAdjacentElement('beforeend',reply);
                reply.addEventListener('click', () => {
                    if (replyFormDiv.classList.contains('hide')) replyFormDiv.classList.remove('hide')
                    else replyFormDiv.classList.add('hide');
                });
            }
            function getFollowButton() {
                const e = stringToNodes('<button class="button1 follow" type="button"><p><img src="https://data.twinoid.com/img/icons/mail.png" />Suivre</p></button>')[0];
                e.addEventListener('click',() => {
                    sendQuery(`mutation Follow(\$threadId:Int!) {
                        f:forumThread_follow(threadId:\$threadId) {
                            __typename
                            success
                            resultCode
                            resultMessage
                        }
                    }`,{threadId:json.data.node.dbId},null,'Follow').then((res) => {
                        if (!res.ok) basicQueryError();
                        return res.json();
                    }).then((json) => {
                        if (json?.data?.f?.success == null) basicQueryError();
                        if (json.data.f.success !== true) return;
                        for (const e of document.querySelectorAll('#forumR .actions .follow')) e.replaceWith(getUnfollowButton());
                    });
                });
                return e;
            }
            function getUnfollowButton() {
                const e = stringToNodes('<button class="button1 follow" type="button"><p><img src="https://data.twinoid.com/img/icons/remove.png" />Ne plus suivre</p></button>')[0];
                e.addEventListener('click',() => {
                    sendQuery(`mutation Unfollow(\$threadId:Int!) {
                        f:forumThread_unfollow(threadId:\$threadId) {
                            __typename
                            success
                            resultCode
                            resultMessage
                        }
                    }`,{threadId:json.data.node.dbId},null,'Unfollow').then((res) => {
                        if (!res.ok) basicQueryError();
                        return res.json();
                    }).then((json) => {
                        if (json?.data?.f?.success == null) basicQueryError();
                        if (json.data.f.success !== true) return;
                        for (const e of document.querySelectorAll('#forumR .actions .follow')) e.replaceWith(getFollowButton());
                    });
                });
                return e;
            }
            if (json.data.node.followingIds.includes(json.data.viewer.dbId.toString())) {
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
        const e = forumL.querySelector(`tr[data-node-id="\${threadId}"]`);
        if (e == null) return;
        e.dataset.selected = true;
    }
    function setupPagInput(eCont,firstPage,lastPage,before,after) {
        if (eCont == null) {
            eCont = stringToNodes(`<div class="paginationDiv">
                <div>
                    <button class="button1 first" type="button"><img src="https://data.twinoid.com/img/icons/first.png"/></button><!--
                    --><button class="button1 left" type="button"><img src="https://data.twinoid.com/img/icons/left.png"/></button>
                </div>
                <div>
                    <button class="button1 pagination_details" type="button">
                        <p>Page <span class="nPage">?</span> <span class="maxPages">/ <span class="nMaxPages">?</span></span></p>
                    </button>
                </div>
                <div>
                    <button class="button1 right" type="button"><img src="https://data.twinoid.com/img/icons/right.png"/></button><!--
                    --><button class="button1 last" type="button"><img src="https://data.twinoid.com/img/icons/last.png"/></button>
                </div>
            </div>`)[0];
        }

        const pagDetails = eCont.querySelector('.pagination_details');
        const first = eCont.querySelector('.first');
        const left = eCont.querySelector('.left');
        const right = eCont.querySelector('.right');
        const last = eCont.querySelector('.last');
        first.addEventListener('click',firstPage);
        left.addEventListener('click',() => before(10,left.dataset.cursor,0));
        right.addEventListener('click',() => after(10,right.dataset.cursor,0));
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
                pagDetailsInput.remove();
                eNPage.style.display = '';
                pagDetailsInput = null;
                if (isNaN(v)) { b = false; return; }
                if (v <= 1) { firstPage(); b = false; return; }
                if (v >= parseInt(pagDetails.querySelector('.nMaxPages').innerHTML)) { lastPage(); b = false; return; }

                const currPage = parseInt(eNPage.innerText);
                if (v >= currPage) after(10,right.dataset.cursor,v-currPage-1); 
                else if (v < currPage) before(10,left.dataset.cursor,currPage-v-1);
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
        </div>
        <div class="replyFormDiv">
            <a class="previewToggler" href="#" onclick="return false;">Masquer / afficher l'aperçu de votre message</a>
            <div class="preview">

            </div>
            <form class="replyForm">
                <div class="title">
                    <label for="newThread_title">Titre : </label><input id="newThread_title" class="inputText1" type="text" name="title"/>
                </div>
                <div class="buttonBar">
                    <button class="button1 bold" type="button">G</button><!--
                    --><button class="button1 italic" type="button">I</button><!--
                    --><button class="button1 strike" type="button">Barré</button><!--
                    --><button class="button1 link" type="button">Lien</button><!--
                    --><button class="button1 cite" type="button">Citer</button><!--
                    --><button class="button1 spoil" type="button">Spoil</button>
                </div>
                <textarea name="msg"></textarea>
                <input class="button2" type="submit" value="Envoyer"/>
            </form>
        </div>`.trim());
        for (const node of e) forumR.insertAdjacentElement('beforeend',node);
        setupReplyForm(forumR.querySelector('.replyFormDiv'),(e) => {
            e.preventDefault();
            const data = new FormData(e.target);
            const submitButton = e.target.querySelector('input[type="submit"]');
            if (submitButton.disabled === true) return;
            submitButton.disabled = true;

            sendQuery(`mutation NewThread(\$title:String!,\$tags:[String!]!,\$msg:String!) {
                f:forum_newThread(title:\$title,tags:\$tags,content:\$msg) {
                    __typename
                    success
                    resultCode
                    resultMessage
                    thread {
                        __typename
                        id
                        followingIds
                    }
                }
            }`,{title:data.get('title'),tags:[],msg:data.get('msg')}).then((res) => {
                if (!res.ok) basicQueryError();
                return res.json();
            }).then((json) => {
                if (json?.data?.f?.thread?.id == null) basicQueryError();
                loadThread(json.data.f.thread.id,10);
                loadThreads(10);
            });
        });
    }
    function loadSearchForm() {
        forumR.innerHTML = '';
        const e = stringToNodes(`
        <form id="searchForm">
            <div class="parameters">
                <label for="searchForm_keywords">Mots clés :</label><input id="searchForm_keywords" class="inputText1" type="text" name="keywords"/>
                <!--<label for="searchForm_dateRange">Date :</label><div>
                    <input id="searchForm_fromDate" type="date" name="fromDate"/> -
                    <input id="searchForm_toDate" type="date" name="toDate"/>
                </div>-->
                <label for="searchForm_sortBy">Trier par :</label><div>
                    <input id="searchForm_sortByRelevance" name="sortBy" type="radio" value="ByRelevance" checked="true"/><label for="searchForm_sortByRelevance">Pertinence</label>
                    <input id="searchForm_sortByDate" name="sortBy" type="radio" value="ByDate"/><label for="searchForm_sortByDate">Date</label>
                </div>
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

        const pagDivs = forumR.querySelectorAll('.pagDivDiv');
        for (const node of pagDivs) {
            node.insertAdjacentElement('beforeend',setupPagInput(null,
                () => loadSearchResults(10),
                () => loadSearchResults(0,10),
                (n,cursor,skipPages) => loadSearchResults(null,n,null,cursor,skipPages),
                (n,cursor,skipPages) => loadSearchResults(n,null,cursor,null,skipPages)
            )); 
        }
        
        const searchForm = forumR.querySelector('#searchForm');
        const searchFormResults = forumR.querySelector('#searchFormResults');
        const submitButton = forumR.querySelector('input[type="submit"]');
        let keywords, sortBy, startDate, endDate = '';
        searchForm.addEventListener('submit',(e) => {
            e.preventDefault();
            if (submitButton.disabled === true) return;
            submitButton.disabled = true;

            const data = new FormData(e.target);
            keywords = data.get('keywords');
            sortBy = data.get('sortBy');
            startDate = data.get('fromDate') == '' ? null : data.get('fromDate');
            endDate = data.get('toDate') == '' ? null : data.get('toDate');

            loadSearchResults(10);
        });

        function loadSearchResults(first,last,after,before,skipPages = 0) {
            sendQuery(`query Search(\$keywords:String!,\$first:Int,\$last:Int,\$after:ID,\$before:ID,\$sortBy:SearchSorting!,\$startDate:DateTime,\$endDate:DateTime,\$skipPages:Int!) {
                search(
                    keywords:\$keywords, first:\$first, after:\$after, before:\$before, last:\$last, 
                    sortBy:\$sortBy, startDate:\$startDate, endDate:\$endDate, 
                    skipPages:\$skipPages, withPageCount:true, withLastPageSpecialBehavior:true
                ) {
                    __typename
                    edges {
                        node {
                            __typename
                            thread {
                                __typename
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
                                commentCount
                            }
                            comment {
                                __typename
                                id
                                dbId
                                threadId
                                authorId
                                states
                                content
                                contentWarning
                                deducedDate
                                loadTimestamp
                            }
                            relevance
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
            }`,{keywords:keywords,first:first,last:last,after:after,before:before,sortBy:sortBy,startDate:startDate,endDate:endDate,skipPages:skipPages}).then((res) => {
                if (!res.ok) basicQueryError();
                return res.json();
            }).then((json) => {
                if (json?.data?.search?.edges == null) basicQueryError();
                submitButton.disabled = false;

                searchFormResults.innerHTML = '';
                for (const edge of json.data.search.edges) {
                    const item = edge.node;
                    const e = stringToNodes(`<div class="searchItem">
                        <div class="infos">
                            <p><b>Titre :</b> \${item.thread.title}</p>
                            <p><b>ID Utilisateur :</b> \${item.comment.authorId}</p>
                        </div>
                        <div class="content">\${item.comment.content}</div>
                        <div class="footer">
                            <p>\${item.comment.deducedDate}</p>
                        </div>
                    </div>`)[0];
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
    }
    const forumFooter =  document.querySelector('#forumL .forum_footer');
    setupPagInput(forumFooter,
        () => loadThreads(10),
        () => loadThreads(null,10),
        (n,cursor,skipPages) => loadThreads(null,n,null,cursor,skipPages),
        (n,cursor,skipPages) => loadThreads(n,null,cursor,null,skipPages)
    );
    
    let savedCategories = null;
    function setupReplyForm(replyFormDiv, onSubmit) {
        const replyForm = replyFormDiv.querySelector('.replyForm');
        const replyFormTA = replyFormDiv.querySelector('textarea');
        let bReplyForm = false;
        replyForm.addEventListener('input',() => {
            if (bReplyForm) return; bReplyForm = true;
            sendQuery(`query ParseText(\$msg:String!) {
                parseText(text:\$msg)
            }`,{msg:replyFormTA.value},null,'ParseText').then((res) => {
                bReplyForm = false;
                if (!res.ok) basicQueryError();
                return res.json();
            }).then((json) => {
                if (json?.data?.parseText == null) basicQueryError();
                replyFormDiv.querySelector('.preview').innerHTML = json.data.parseText
            });
        });
        replyForm.addEventListener('submit',(e) => onSubmit(e));

        replyFormDiv.querySelector('.previewToggler').addEventListener('click',() => {
            var v = replyFormDiv.querySelector('.preview').style.display;
            replyFormDiv.querySelector('.preview').style.display = v == 'none' ? '' : 'none';
        });

        function quickInputInsert(s1,s2) {
            const msg = replyFormTA.value;
            const start = replyFormTA.selectionStart;
            const end = replyFormTA.selectionEnd;
            if (s2 == null) s2 = s1;
            replyFormTA.value = msg.substring(0,start) + s1 + msg.substring(start,end) + s2 + msg.substring(end);
            replyFormTA.focus();
            const diff = replyFormTA.selectionEnd-replyFormTA.selectionStart;
            replyFormTA.selectionStart = start+s1.length;
            replyFormTA.selectionEnd = end+s1.length+diff;
            replyForm.dispatchEvent(new InputEvent('input'));
        }
        replyForm.querySelector('.buttonBar .bold').addEventListener('click',() => quickInputInsert('**'));
        replyForm.querySelector('.buttonBar .italic').addEventListener('click',() => quickInputInsert('//'));
        replyForm.querySelector('.buttonBar .strike').addEventListener('click',() => quickInputInsert('--'));
        replyForm.querySelector('.buttonBar .link').addEventListener('click',() => {
            const link = prompt("Le lien que vous voulez insérer :");
            if (link == null) return;
            const txt = replyFormTA.selectionStart === replyFormTA.selectionEnd ? prompt("Entrer le texte de votre lien :")??''  : '';
            quickInputInsert(`[link=\${link}]\${txt}`,'[/link]');
        });
        replyForm.querySelector('.buttonBar .cite').addEventListener('click',() => quickInputInsert('[cite]','[/cite]'));
        replyForm.querySelector('.buttonBar .spoil').addEventListener('click',() => quickInputInsert('[spoil]','[/spoil]'));

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
            }`).then((res) => {
                if (!res.ok) basicQueryError();
                return res.json();
            }).then((json) => {
                if (json?.data?.viewer?.emojis?.edges == null) basicQueryError();
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
        }
    }

    document.querySelector('.newThreadLoader').addEventListener('click',loadNewThreadForm);
    document.querySelector('.searchLoader').addEventListener('click',loadSearchForm);
    document.querySelector('.refreshThreads').addEventListener('click',() => {
        const pageNumber = parseInt(document.querySelector('#forumL .nPage').innerText);
        if (isNaN(pageNumber)) return;
        loadThreads(10,null,null,null,pageNumber-1);
    });

    loadThreads(10);

    const m = new RegExp("^$root/forum/(\\\d+)").exec(location.href);
    if (m != null) loadThread(`forum_\${m[1]}`,10);
    _loadPageMidProcesses['forumMP'] = (url,displayedURL) => {
        if (document.querySelector('#mainDiv_forum') == null) return false;
        const m = new RegExp("^$root/forum/(\\\d+)").exec(displayedURL);
        if (m == null) return false;
        loadThread(`forum_\${m[1]}`,10);
        return true;
    };

    const mql = window.matchMedia("(max-width: 600px)");
    const fmql = (mql) => {
        if (mql.matches && !mobileMode) {
            if (forumR.innerHTML == '') forumL.style.display = 'none';
            else forumR.style.display = 'none';
            for (const e of forumR.querySelectorAll('.actions .back')) e.style.display = '';
            mobileMode = true;
        } else {
            forumL.style.display = forumR.style.display = '';
            for (const e of forumR.querySelectorAll('.actions .back')) e.style.display = 'none';
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
        font-size: 0.8rem;
    }
    #mainDiv_forum .button1:hover {
        background-color: #3b4151;
        border-color: var(--color-black-1);
    }
    #mainDiv_forum .button1 img {
        vertical-align: text-bottom;
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
        font-size: 0.9rem;
        border: 0;
        box-shadow: inset 0px 2px 2px #c7c5c0;
        padding: 0.1rem 0px 0.1rem 0px;
    }
    #mainDiv_forum .replyFormDiv {
        margin-top: 2rem;
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
        font-size: 0.8rem;
        padding: 0.6rem 0px 0px 6rem;
        margin: 0px 0px 1rem 0px;
    }
    #mainDiv_forum .replyFormDiv .preview blockquote {
        padding: 0.3rem 0px 0.3rem 0.3rem;
        border-left: 1px dashed rgba(0,0,0, 0.6);
        border-bottom: 1px dashed rgba(0,0,0, 0.6);
        font-style: italic;
        opacity: 0.7;
        margin: 0.3rem 0.3rem 0.5rem 0.3rem;
    }
    #mainDiv_forum .replyFormDiv .preview .spoil {
        cursor: help;
        background-image: url(https://data.twinoid.com/img/design/spoiler.png);
    }
    #mainDiv_forum .replyFormDiv .preview .spoil .spoilTxt {
        opacity:0;
    }
    #mainDiv_forum .replyFormDiv .preview .spoil:hover {
        background-image: url(https://data.twinoid.com/img/design/spoiler_hover.png);
    }
    #mainDiv_forum .replyFormDiv .preview .spoil:hover .spoilTxt {
        opacity:unset;
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
    #mainDiv_forum .replyFormDiv .replyForm div.title {
        display: flex;
        width: 100%;
        align-items: center;
        padding: 0px 0px 0.4rem 1rem;
    }
    #mainDiv_forum .replyFormDiv .replyForm div.title label {
        width: 8%;
        font-size: 0.9rem;
        padding: 0.1rem 0.3rem 0px 0px;
        font-weight: bold;
    }
    #mainDiv_forum .replyFormDiv .replyForm .buttonBar {
        margin: 0rem 0px 0.2rem 0.1rem;
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
    #forumL {
        flex: 1 1 35%;
    }
    #forumR {
        flex: 1 1 65%;
    }
    #forum_content {
        display: flex;
        gap: 2rem;
        margin: 4rem auto 0px auto;
        width: 980px;
        max-width: 99%;
        justify-content: center;
    }
    #forum_threads {
        margin: 0px 0px 1rem 0px;
        width: 100%;
    }
    #forum_threads a {
        text-decoration: none;
        color: black;
        display: inline-block;
        width: 100%;
        height: 100%;
    }
    #forum_threads thead {
        font-size: 0.6rem;
    }
    #forum_threads tbody td {
        height: 35px;
    }
    #forum_threads .selectArrow {
        position: absolute;
        transform: translate(-100%,0px);
        display: none;
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
        width: 10%;
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
        width: 20%;
        text-align: end;
        padding: 0.2rem 0px 0px 0px;
    }
    #forum_threads .quickDetails .nAnswers {
        font-weight: bold;
        font-size: 1.1rem;
    }
    #forum_threads .quickDetails .author {
        font-size: 0.7rem;
    }
    #forum_threads .title {
        background-color: #B2B4BA;
        width: 70%;
    }
    #forum_threads .title a {
        display: flex;
        align-items: center;
    }
    #forum_threads .delimiter {
        background-color: var(--color-black-2);
        height: 14px;
        font-size: 0.9rem;
        color: white;
        text-align: center;
    }
    #forum_comments {
        margin: 2.5rem 0px 0px 0px;
    }
    #forum_comments .header {
        background-color: var(--color-black-2);
        border-radius: 3px;
        color: white;
        position: relative;
        height: 2.3rem;
    }
    #forum_comments .header .name {
        position: absolute;
        left: 6.1rem;
        top: 0.3rem;
        font-weight: bold;
        font-size: 0.92rem;
    }
    #forum_comments .date {
        position: absolute;
        right: 0.4rem;
        display: inline;
        top: 0.4rem;
        font-size: 0.8rem;
        color: #9D9EA6;
    }
    #forum_comments .avatarDiv {
        width: 80px;
        max-height: 80px;
        position: absolute;
        top: 50%;
        left: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: black;
        transform: translate(-0%, -50%);
    }
    #forum_comments .avatar {
        max-width: 80px;
    }
    #forum_comments .body {
        background-color: white;
        height: 100px;
        margin: 0px 0.2rem 2.3rem 0.3rem;
        box-shadow: 0px 0px min(3px,0.2rem) 0px #00000088;
        padding: 0.6rem 0px 0px 5.9rem;
        font-size: 0.9rem;
    }
    #searchForm {
        padding: 10px;
        margin-bottom: 10px;
        background-image: url(https://data.twinoid.com/img/design/gripBg.png);
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
        background-image: url(https://data.twinoid.com/img/design/gripBg.png);
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
    .forum_mainBar {
        margin-bottom: 1rem;
    }
    .forum_mainBar_sub1 {
        background-color: #B63B00;
        font-size: 1rem;
        color: white;
        font-weight: bold;
        padding: 0.1rem 0.3rem 0px 0.3rem;
    }
    .forum_mainBar_sub2 {
        display: flex;
        justify-content: space-between;
        background-color: #B63B00;
        opacity: 0.83;
        padding: 0.4rem;
    }
    .forum_footer, .pagDivDiv {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background-color: var(--color-black-2);
        padding: 0.4rem;
    }
    .forum_footer .actions, .forum_mainBar .actions {
        flex: 1 2 60%;
    }
    .forum_footer .paginationDiv, .forum_mainBar .paginationDiv, .pagDivDiv .paginationDiv {
        display: flex;
        justify-content: space-between;
        flex: 1 0 43%;
        align-items: center;
    }
    .forum_footer .actions + .paginationDiv, .forum_mainBar .paginationDiv {
        padding-left: 1.5%;
        border-left: 1px dashed black;
    }
    .pagination_details:hover .nPage {
        border: 1px dashed white;
        box-sizing: border-box;
    }
    .pagination_details .nPage {
        /* display: block; */
        font-size: 1.1rem;
        vertical-align: -0.15rem;
    }
    .pagination_details .maxPages {
        font-size: 0.8rem;
        opacity: 0.8;
        vertical-align: -0.1rem;
    }
    .pagination_details input[type="text"]{
        width: 2.5rem;
        text-align: center;
        border: 0;
        box-shadow: inset 0px 2px 3px 0px black;
    }
    CSS];
}

function getVersionHistoryElem() {
    global $isAuth;
    return ['html' => <<<HTML
    <div id="mainDiv_versionHistory" class="authPadded" data-is-auth="$isAuth">
        <div class="versions">
            <button selected="true">0.1</button>
        </div>
        <div class="versionDescription">
            <h2>Mise à jour 0.1 <span>Sortie : 1 Août 2023</span></h2>
            <p class="subheader">— La première version. Recréation partielle du système de forum Twinoid et autres fonctionnalités basiques pour commencer. Incomplet, pas bien testé et un peu moche, mais c'est un bon début.</p>
            <div class="main">
                <section>
                    <h3>Forum</h3>
                    <section>
                        <h4>Récapitulatif des fonctionnalités</h4>
                        <ul>
                            <li>Création de topics et commentaires</li>
                            <li>Stylisations/fonctionnalités des posts: Gras, Italique, Barré, Insertions de liens, Citations, Spoil</li>
                            <li>Suivis de topics et réceptions de notifications</li>
                            <li>Émoticônes Twinoid disponibles en fonction de l'utilisateur (voir <a href="https://twinoid.com/tid/forum#!view/161558%7Cthread/66116573" target="_blank">ce topic</a> pour importer vos émojis)</li>
                            <li>Fonction recherche trèèès basique (uniquement pour les topics sauvegardés de Twinoid pour l'instant et pas formaté)</li>
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
            </div>
        </div>
    </div>
    HTML,
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
    #mainDiv_versionHistory .versions {
        display: flex;
        justify-content: center;
        margin: 1.5rem 0px;
        padding: 0.3rem 0px;
        border-top: 1px dashed black;
        border-bottom: 1px dashed black;
    }
    #mainDiv_versionHistory .versions button {
        background-color: var(--color-black-2);
        border: 0;
        border-radius: 0.2em;
        padding: 0.2em 0.8em;
        color: white;
        font-weight: bold;
    }
    #mainDiv_versionHistory .versions button[selected="true"] {
        background-color: var(--color-orange-2);
    }
    #mainDiv_versionHistory .versionDescription {
        margin: 2rem 8%;
    }
    #mainDiv_versionHistory .versionDescription .main {
        margin: 1rem 0px 0px 0px;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    #mainDiv_versionHistory .versionDescription h2 {
        color: var(--color-orange-text-1);
        font-weight: bold;
        font-size: 1.8rem;
    }
    #mainDiv_versionHistory .versionDescription h2 span {
        color: grey;
        font-size: 0.6em;
    }
    #mainDiv_versionHistory .versionDescription h3 {
        color: var(--color-black-2);
        font-size: 1.6rem;
        border-bottom: 1px solid black;
        margin: 0px 0px 0.7em 0px;
        padding: 0px 0px 0.1em 0px;
    }
    #mainDiv_versionHistory .versionDescription h4 {
        font-size: 1.25rem;
        margin: 1em 0px 0.3em 0px;
    }
    CSS];
}
?>