(() => {
  const signupButton = document.querySelector("#signup_btn");

  signupButton.addEventListener("click", () => {
    window.location.assign("/signup");
  });
})();
