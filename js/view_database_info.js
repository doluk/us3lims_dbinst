/*
 * view_database_info.js
 *
 * some common functions called by the database info view. To copy all the details
 */

function copyToClipboard(...others) {
    var tempItem = document.createElement('input');

    tempItem.setAttribute('type','textarea');
    tempItem.setAttribute('id', 'databaseInfo')
    tempItem.setAttribute('display','none');
    let content = "";
    for (let e of others) {
        let content_piece = e;
        if (e instanceof HTMLElement) {
            content_piece = e.innerHTML;
        }
        content = content + "|" + content_piece;
    }
    console.log(content);

    tempItem.setAttribute('value',content);
    document.body.appendChild(tempItem);

    tempItem.select();
    tempItem.setSelectionRange(0, 99999999);
    document.execCommand('Copy');
    console.log("Copied th text: " + tempItem.value);

    tempItem.parentElement.removeChild(tempItem);
    console.log(content);
}