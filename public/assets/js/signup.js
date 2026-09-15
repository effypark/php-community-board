(() => {
  const idInput = document.querySelector("#user_id");
  const checkButton = document.querySelector("#check_id_btn");
  const message = document.querySelector("#user_id_message");
  const submitButton = document.querySelector("#submit_btn");

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

  if (!idInput || !checkButton || !message || !submitButton) {
    return;
  }

  const form = idInput.form;

  let inputVersion = 0;
  let checkedId = null;

  submitButton.disabled = true;
  checkButton.disabled = false;

  idInput.addEventListener("input", () => {
    inputVersion += 1;
    checkedId = null;

    message.textContent = "";
    checkButton.disabled = false;
    submitButton.disabled = true;
  });

  checkButton.addEventListener("click", async () => {
    const userId = idInput.value.trim();

    checkedId = null;
    submitButton.disabled = true;

    if (!userId) {
      message.textContent = "아이디를 입력해주세요.";
      idInput.focus();
      return;
    }

    const requestVersion = inputVersion;

    checkButton.disabled = true;

    try {
      const response = await fetch("/users/check-id", {
        method: "POST",
        headers: {
          Accept: "application/json",
          "X-CSRF-Token": csrfToken,
        },
        body: new URLSearchParams({
          user_id: userId,
        }),
      });

      if (!response.ok) {
        throw new Error("아이디 중복 확인 요청에 실패했습니다.");
      }

      const result = await response.json();

      if (requestVersion !== inputVersion) {
        return;
      }

      message.textContent = result.message;

      if (result.available === true) {
        checkedId = userId;
        submitButton.disabled = false;
      }
    } catch (error) {
      if (requestVersion === inputVersion) {
        checkedId = null;
        submitButton.disabled = true;
        message.textContent =
          "중복 확인 중 오류가 발생했습니다. 다시 시도해주세요.";
      }

      console.error(error);
    } finally {
      // 오래된 요청이 현재 버튼 상태를 변경 못하게함
      if (requestVersion === inputVersion) {
        // 확인성공하면 비활성화하고, 실패하면 활성화
        checkButton.disabled = checkedId !== null;
      }
    }
  });

  form?.addEventListener("submit", (event) => {
    if (checkedId === null || checkedId !== idInput.value.trim()) {
      event.preventDefault();

      checkedId = null;
      submitButton.disabled = true;
      checkButton.disabled = false;

      message.textContent = "아이디 중복 확인을 해주세요.";
      idInput.focus();
    }
  });
})();
