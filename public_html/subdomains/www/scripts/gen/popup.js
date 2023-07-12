<?php
function getPopupDiv() {
    return ['html' => <<<HTML
    <div id="popupDiv" style="display:none;">
        
    </div>

    HTML,
    'js' => <<<JAVASCRIPT
    const popupDiv = document.querySelector('#popupDiv');
    popupDiv.openTo = (sel) => {
        popupDiv.style.display = '';
        for (const e of document.querySelectorAll('#popupDiv > form, #popupDiv > div')) e.style.display = 'none';
        const e = popupDiv.querySelector(sel);
        if (e != null) e.style.display = '';
    }; 
    popupDiv.close = () => { popupDiv.style.display = 'none'; };
    
    JAVASCRIPT,
    'css' => <<<CSS
    #popupDiv {
        position: fixed;
        top: 0px;
        left: 0px;
        background-color: #0000003b;
        width: 100%;
        height: 100%;
        z-index: 100;
    }
    #popupDiv > form, #popupDiv > div {
        position: relative;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 50%;
        border-radius: 6px;
        overflow: hidden;
        padding-bottom: 1rem;
    }
    .popupContainer {
        background: var(--bg-gradient-1);
        color: var(--color-black-1);
        display: flex;
        flex-direction: column;
    }
    .popupContainer input[type="text"], .popupContainer input[type="password"] {
        background-color: var(--color-grey-darker);
        border: 1px solid #9F9D9B;
        height: 2rem;
        font-size: 1.2rem;
        box-shadow: inset 0px 1px 2px #C7C5C0;
        border: 0px;
        border-top: 1px solid #C7C6C0;
        border-bottom: 1px solid white;
        outline: 1px solid #9f9d9b;
    }
    .popupContainer input[type="submit"], .popupContainer input[type="button"] {
        background-color: var(--color-orange-2);
        color: white;
        width: 100%;
        height: 2rem;
        font-size: 1.2rem;
        border:0;
        text-shadow: 0px 1px 2px black;
        box-shadow: inset 0px 15px 0px #FF7900, inset 0px -1px 0px #A63F00, 0px 2px 2px rgba(0,0,0,0.4);
        cursor: pointer;
    }
    .popupContainer input[type="submit"]:hover, .popupContainer input[type="button"]:hover {
        border: 1px solid white;
    }
    CSS];
}
$getPopupDiv = getPopupDiv()['html'];

function getConnexionForm() {
    return ['html' => <<<HTML
    <div id="connexionForm" class="popupContainer" style="display:none;">
        <div id="connexionForm_titleDiv">
            <p>•••</p>
        </div>
        <form id="connexionForm_connect" class="connexionForm_subDiv" style="display:none;" data-title-to="Connexion">
            <div>
                <label for="connexionForm_connect_username">Nom d'utilisateur</label>
                <input type="text" id="connexionForm_connect_username" name="connUsername"/>
            </div>
            <div>
                <label for="connexionForm_connect_password">Mot de passe</label>
                <input type="password" id="connexionForm_connect_password" name="connPwd"/>
            </div>
            <div id="connexionForm_connect_subActions">
                <p><a id="connexionForm_connect_link_invite" href="#" onclick="return false;">J'ai un code d'invitation !</a></p>
                <p><a href="#" onclick="return false;">J'ia oublié mon mdp :C</a></p>
            </div>
            <input type="submit" value="Se connecter"/>
        </form>
        <form id="connexionForm_register" class="connexionForm_subDiv" style="display:none;" data-title-to="Inscription">
            <div>
                <label for="connexionForm_register_username">Nom d'utilisateur</label>
                <input type="text" id="connexionForm_register_username" name="connUsername"/>
            </div>
            <div>
                <label for="connexionForm_register_password">Mot de passe</label>
                <input type="password" id="connexionForm_register_password" name="connPwd"/>
            </div>
            <input type="submit" value="S'inscrire"/>
        </form>
        <form id="connexionForm_invite" class="connexionForm_subDiv" style="display:none;" data-title-to="Code d'invitation">
            <p class="goBack"><a href="#" onclick="return false;">Retour</a></p>
            <div>
                <label for="connexionForm_invite_code">Code d'invitation</label>
                <input type="text" id="connexionForm_invite_code" name="inviteCode"/>
            </div>
            <input type="submit" value="Valider"/>
        </form>
    </div>
    HTML,
    'js' => <<<JAVASCRIPT
    const connexionForm = document.querySelector('#connexionForm');
    connexionForm.openTo = (sel) => {
        connexionForm.style.display = '';
        for (const e of document.querySelectorAll('.connexionForm_subDiv')) e.style.display = 'none';
        const e = connexionForm.querySelector(sel);
        if (e.dataset.titleTo != null) connexionForm.querySelector('#connexionForm_titleDiv p').innerHTML = e.dataset.titleTo;
        if (e != null) e.style.display = '';
    };
    connexionForm.close = () => { connexionForm.style.display = 'none'; };

    const connect = connexionForm.querySelector('#connexionForm_connect');
    const register = connexionForm.querySelector('#connexionForm_register');
    const invite = connexionForm.querySelector('#connexionForm_invite');
    console.log();
    connect.querySelector('#connexionForm_connect_link_invite').addEventListener('click', () => {
        if (getCookie('invite_sid') != null) connexionForm.openTo('#connexionForm_register');
        else connexionForm.openTo('#connexionForm_invite');
    });
    document.querySelector('#connexionForm_invite .goBack a').addEventListener('click', () => connexionForm.openTo('#connexionForm_connect'));
  
    connect.addEventListener('submit', (event) => {
        event.preventDefault();
        const submit = connect.querySelector('input[type="submit"]');
        const submitOldValue = submit.value;
        submit.value = "•••";
        submit.disabled = true;

        const username = connexionForm.querySelector('#connexionForm_connect_username').value;
        const password = connexionForm.querySelector('#connexionForm_connect_password').value;
        sendQuery(`mutation LoginUser(\$username:String!, \$password:String!) {
            loginUser(username:\$username, password:\$password) {
                __typename
                success
                resultCode
                resultMessage
                registeredUser {
                    __typename
                    id
                    name
                }
            }
        }`,{username:username,password:password}).then((res) => {
            if (!res.ok) basicQueryError();
            else return res.json();
        }).then((json) => {
            if (json?.data?.loginUser?.success == null) basicQueryError();
            if (json.data.loginUser.success === true) {
                location.reload();
            } else {
                alert('Nom et/ou mot de passe incorrect(s).');
                submit.value = submitOldValue;
                submit.disabled = false;
            }
        });
    });
    invite.addEventListener('submit', (event) => {
        event.preventDefault();
        const submit = invite.querySelector('input[type="submit"]');
        const submitOldValue = submit.value;
        submit.value = "•••";
        submit.disabled = true;

        const code = connexionForm.querySelector('#connexionForm_invite_code').value;
        sendQuery(`mutation ProcessInviteCode(\$code:String!) {
            processInviteCode(code:\$code) {
                __typename
                success
                resultCode
                resultMessage
            }
        }`,{code:code},null,'ProcessInviteCode').then((res) => {
            if (!res.ok) basicQueryError();
            else return res.json();
        }).then((json) => {
            if (json?.data?.processInviteCode?.success == null) basicQueryError();
            if (json.data.processInviteCode.success === true) {
                connexionForm.openTo('#connexionForm_register');
            } else {
                alert('Code non valide.');
                submit.value = submitOldValue;
                submit.disabled = false;
            }
        });
    });
    register.addEventListener('submit', (event) => {
        event.preventDefault();
        const submit = register.querySelector('input[type="submit"]');
        const submitOldValue = submit.value;
        console.log(submitOldValue);
        submit.value = "•••";
        submit.disabled = true;

        const username = connexionForm.querySelector('#connexionForm_register_username').value;
        const password = connexionForm.querySelector('#connexionForm_register_password').value;
        sendQuery(`mutation RegisterUser(\$username:String!,\$password:String!) {
            registerUser(username:\$username,password:\$password) {
                __typename
                success
                resultCode
                resultMessage
            }
        }`,{username:username, password:password},null,'RegisterUser').then((res) => {
            if (!res.ok) basicQueryError();
            else return res.json();
        }).then((json) => {
            console.log(json);
            if (json?.data?.registerUser?.success == null) basicQueryError();
            
            submit.value = submitOldValue;
            submit.disabled = false;
            if (json.data.registerUser.success === true) {
                alert('Vous pouvez maintenant vous connecter !');
                connexionForm.openTo('#connexionForm_connect');
            } else {
                alert('Nom et/ou mot de passe incorrect(s).');
            }
        });
    });

    JAVASCRIPT,
    'css' => <<<CSS
    #connexionForm label {
        font-weight: bold;
        font-size: 1.2rem;
        padding: 0.2rem 0px;
    }
    #connexionForm_titleDiv {
        background-color: var(--color-black-1);
        color: white;
        height: 3rem;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1rem;
    }
    #connexionForm_connect_subActions {
        text-align: right;
        font-size: 0.8rem;
    }
    #connexionForm_connect_subActions p {
        padding: 0.1rem 0px;
    }
    .connexionForm_subDiv {
        display: flex;
        flex-direction: column;
        gap: 1.3rem;
        margin: 0px 0.8rem;
    }
    .connexionForm_subDiv div {
        display: flex;
        flex-direction: column;
    }
    .goBack {
        position: absolute;
        top: 1rem;
        left: 2rem;
    }
    .goBack a {
        color: coral;
    }
    CSS];
}
$getConnexionForm = getConnexionForm()['html'];

function getDisconnectElem() {
    return ['html' => <<<HTML
    <div id="askDisconnect" class="popupContainer">
        <input id="askDisconnect_cancel" type="button" value="Retour"/>
        <input id="askDisconnect_disconnect" type="button" value="Se déconnecter"/>
    </div>

    HTML,
    'js' => <<<JAVASCRIPT
    const cancel = document.querySelector('#askDisconnect_cancel');
    cancel.addEventListener('click', popupDiv.close);
    document.querySelector('#askDisconnect_disconnect').addEventListener('click', () => {
        sendQuery(`mutation LogoutUser {
            logoutUser {
                __typename
                success
                resultCode
                resultMessage
            }
        }`).then((res) => {
            if (!res.ok) basicQueryError();
            else return res.json();
        }).then((json) => {
            if (json?.data?.logoutUser?.success == null) basicQueryError();
            location.reload();
        });
    });

    JAVASCRIPT,
    'css' => <<<CSS
    #askDisconnect {
        padding: 1rem;
        display: flex;
        gap: 1rem;
    }

    CSS];
}
$getDisconnectElem = getDisconnectElem()['html'];

echo <<<JAVASCRIPT
function getPopupDiv() {
    return `$getPopupDiv`;
}

function getConnexionForm() {
    return `$getConnexionForm`;
}

function getDisconnectElem() {
    return `$getDisconnectElem`;
}
JAVASCRIPT;