# The King's Escape

In this game, the player controls a king attempting to escape from an enemy invasion. The chessboard represents the battlefield, and different pieces block or assist the king’s movement.
The king starts at a designated square and must reach the opposite end of the board while avoiding enemy pieces. With each turn, new pieces appear on the board, either aiding or obstructing the king’s escape.
The player must navigate the board strategically, using available paths to reach safety.

The goal is to determine the earliest possible move at which the king can escape the battlefield.

< --- Game Mechanics --- >

The king starts on (x1, y1), and the exit is at (x2, y2).
Each turn, new enemy pieces are placed on the board based on an array A, where A[i] represents an enemy piece appearing at a specific position.
The king can move one square in any direction but can only land on unoccupied tiles.
The objective is to determine the earliest move at which the king reaches (x2, y2).

This setup provides a structured way to track chess movements using MySQL while maintaining an engaging gameplay mechanic.
