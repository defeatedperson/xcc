function checkLength(input, maxLength) {
    if (input.value.length > maxLength) {
        input.value = input.value.slice(0, maxLength);
        alert('输入内容超过最大长度限制！');
    }
}